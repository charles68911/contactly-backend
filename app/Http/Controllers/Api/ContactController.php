<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    // GET ALL CONTACTS + SEARCH + CATEGORY FILTER
    public function index(Request $request)
    {
        $search = trim($request->query('search', ''));
        $category = trim($request->query('category', ''));

        $contacts = $request->user()
            ->contacts()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%{$search}%")
                        ->orWhere('last_name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            })
            ->when($category !== '', function ($query) use ($category) {
                $query->where('category', $category);
            })
            ->latest()
            ->get();

        return ContactResource::collection($contacts)
            ->additional(['success' => true]);
    }

    // GET FAVORITE CONTACTS
    public function favorites(Request $request)
    {
        $favorites = $request->user()
            ->contacts()
            ->where('is_favorite', true)
            ->latest()
            ->get();

        return ContactResource::collection($favorites)
            ->additional(['success' => true]);
    }

    // DASHBOARD SUMMARY
    public function dashboard(Request $request)
    {
        $contacts = $request->user()->contacts();

        return response()->json([
            'success' => true,
            'data' => [
                'total_contacts' => $contacts->count(),

                'favorite_contacts' => (clone $contacts)
                    ->where('is_favorite', true)
                    ->count(),

                'family_contacts' => (clone $contacts)
                    ->where('category', 'Family')
                    ->count(),

                'friends_contacts' => (clone $contacts)
                    ->where('category', 'Friends')
                    ->count(),

                'work_contacts' => (clone $contacts)
                    ->where('category', 'Work')
                    ->count(),
            ],
        ]);
    }

    // TOGGLE CONTACT FAVORITE STATUS
    public function toggleFavorite(Request $request, string $id)
    {
        $validated = $request->validate([
            'is_favorite' => 'required|boolean',
        ]);

        $contact = $request->user()
            ->contacts()
            ->findOrFail($id);

        $contact->update([
            'is_favorite' => $validated['is_favorite'],
        ]);

        return (new ContactResource($contact->fresh()))
            ->additional([
                'success' => true,
                'message' => $validated['is_favorite']
                    ? 'Contact added to favorites.'
                    : 'Contact removed from favorites.',
            ]);
    }

    // CREATE CONTACT
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'category' => 'sometimes|required|string|max:255',
            'profile_image_url' => 'nullable|string|max:255',
            'is_favorite' => 'sometimes|boolean',
        ]);

        $contact = $request->user()
            ->contacts()
            ->create($validated);

        return (new ContactResource($contact))
            ->additional([
                'success' => true,
                'message' => 'Contact created successfully.',
            ])
            ->response()
            ->setStatusCode(201);
    }

    // GET SINGLE CONTACT
    public function show(Request $request, string $id)
    {
        $contact = $request->user()
            ->contacts()
            ->findOrFail($id);

        return (new ContactResource($contact))
            ->additional(['success' => true]);
    }

    // UPDATE CONTACT
    public function update(Request $request, string $id)
    {
        $contact = $request->user()
            ->contacts()
            ->findOrFail($id);

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string',
            'category' => 'sometimes|required|string|max:255',
            'profile_image_url' => 'sometimes|nullable|string|max:255',
            'is_favorite' => 'sometimes|boolean',
        ]);

        $contact->update($validated);

        return (new ContactResource($contact->fresh()))
            ->additional([
                'success' => true,
                'message' => 'Contact updated successfully.',
            ]);
    }

    // DELETE CONTACT
    public function destroy(Request $request, string $id)
    {
        $contact = $request->user()
            ->contacts()
            ->findOrFail($id);

        $contact->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contact deleted successfully.',
        ]);
    }
}