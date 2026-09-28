<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminSettingsController extends Controller
{
    // =====================================================
    // GET PROFILE ADMIN + RESTAURANT
    // =====================================================

    public function profile(Request $request)
    {
        $admin = $request->user();

        $restaurant = Restaurant::find(
            $admin->restaurant_id
        );

        return response()->json([
            'message' => 'Profil admin berhasil diambil',

            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'restaurant_id' => $admin->restaurant_id,

                'restaurant' => $restaurant,
            ],
        ]);
    }


    // =====================================================
    // UPDATE PROFILE ADMIN + RETURN RESTAURANT
    // =====================================================

    public function updateProfile(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' =>
                'required|string|max:255',

            'email' =>
                'required|email|max:255|unique:admins,email,' .
                $admin->id,
        ]);

        $admin->update([
            'name' =>
                $validated['name'],

            'email' =>
                $validated['email'],
        ]);

        // Restaurant selalu diambil berdasarkan
        // restaurant_id milik admin yang sedang login.
        $restaurant = Restaurant::find(
            $admin->restaurant_id
        );

        return response()->json([
            'message' =>
                'Profil admin berhasil diperbarui',

            'data' => [
                'id' =>
                    $admin->id,

                'name' =>
                    $admin->name,

                'email' =>
                    $admin->email,

                'restaurant_id' =>
                    $admin->restaurant_id,

                'restaurant' =>
                    $restaurant,
            ],
        ]);
    }


    // =====================================================
    // UPDATE PASSWORD
    // =====================================================

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' =>
                'required|string',

            'password' =>
                'required|string|min:8|confirmed',
        ]);

        $admin = $request->user();

        if (
            !Hash::check(
                $validated['current_password'],
                $admin->password
            )
        ) {
            return response()->json([
                'message' =>
                    'Password lama tidak sesuai.',
            ], 422);
        }

        $admin->update([
            'password' =>
                Hash::make(
                    $validated['password']
                ),
        ]);

        return response()->json([
            'message' =>
                'Password berhasil diperbarui.',
        ]);
    }
}