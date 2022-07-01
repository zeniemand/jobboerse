<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ListingController extends Controller
{
    public function index(Request $request)
    {
        $listings = Listing::where('is_active', true)
            ->with('tags')
            ->latest()
            ->get();

        $tags = Tag::orderBy('name')
            ->get();

        if ($request->has('s')) {
            $query = strtolower($request->get('s'));
            $listings = $listings->filter(function ($listing) use($query) {
                if (Str::contains(strtolower($listing->title), $query)) {
                    return true;
                }

                if (Str::contains(strtolower($listing->company), $query)) {
                    return true;
                }

                if (Str::contains(strtolower($listing->location), $query)) {
                    return true;
                }

                return false;
            });
        }

        if ($request->has('tag')) {
            $tag = $request->get('tag');

            $listings = $listings->filter(function ($listing) use($tag){
                return $listing->tags->contains('slug', $tag);
            });
        }

        return view('listings.index', compact('listings', 'tags'));
    }

    public function show(Listing $listing, Request $request)
    {
        return view('listings.show', compact('listing'));
    }

    public function apply(Listing $listing, Request $request)
    {
        $listing->clicks()
            ->create([
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip()
            ]);
        return redirect()->to($listing->apply_link);
    }

    public function create()
    {
        return view('listings.create');
    }

    public function store(Request $request)
    {
        $request->validate($this->getListingValidationArray());
        $user = $this->getAuthStripeUser($request);
        return $this->processPayment($request, $user);
    }

    /**
     * @return string[]
     */
    private function getListingValidationArray(): array
    {
        $validationArray = [
            'title' => 'required',
            'company' => 'required',
            'logo' => 'file|max:2048',
            'location' => 'required',
            'apply_link' => 'required|url',
            'content' => 'required',
            'payment_method_id' => 'required',
        ];

        if(!Auth::check()){
            $validationArray = array_merge($validationArray, [
                'email' => 'required|email|unique:users',
                'password' => 'required|confirmed|min:5',
                'name' => 'required',
            ]);
        }

        return $validationArray;
    }

    /**
     * @param Request $request
     * @return object
     */
    private function getAuthStripeUser(Request $request): object
    {
        $user = Auth::user();

        if (!$user) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),

            ]);

            $user->createAsStripeCustomer();

            Auth::login($user);
        }

        return $user;
    }

    /**
     * Process payment and create new Listing
     * @param Request $request
     * @param  $user
     * @return \Illuminate\Http\RedirectResponse
     */
    private function processPayment(Request $request, $user)
    {
        try{
            $amount = 4900;
            if ($request->filled('is_highlighted')) {
                $amount+= 900;
            }

            $user->charge($amount, $request->payment_method_id);
            $listing = $this->createPayedListing($request, $user);
            $this->attachTagToPayedListing($request, $listing->id);

            return redirect()->route('dashboard');

        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    private function createPayedListing(Request $request,User $user)
    {
        $md = new \ParsedownExtra();
        return $user->listings()
            ->create([
                'title' => $request->title,
                'slug' => Str::slug($request->title) . '-' . rand(1111, 9999),
                'company' => $request->company,
                'logo' => basename($request->file('logo')->store('public')),
                'location' => $request->location,
                'apply_link' => $request->apply_link,
                'content' => $md->text($request->input('content')),
                'is_highlighted' => $request->filled('is_highlighted'),
                'is_active' => true
            ]);
    }

    private function attachTagToPayedListing(Request $request, $listingId)
    {
        foreach (explode(',', $request->tags) as $requestTag) {
            $tag = Tag::firstOrCreate(
                [
                    'slug' => Str::slug(trim($requestTag))
                ],
                [
                    'name' => ucwords(trim($requestTag))
                ]
            );

            $tag->listings()->attach($listingId);
        }
    }

}
