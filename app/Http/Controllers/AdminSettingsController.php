<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminSettingsController extends Controller
{
    // =========================================================
    // Menampilkan profil admin yang sedang login
    // =========================================================
    public function profile(Request $request)
    {
        $admin = $request->user();

        return response()->json([
            'message' => 'Profil admin berhasil diambil',
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'restaurant_id' => $admin->restaurant_id,
            ]
        ]);
    }

    // =========================================================
    // Mengubah nama dan email admin
    // =========================================================
    public function updateProfile(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('admins', 'email')->ignore($admin->id),
            ],
        ]);

        $admin->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        return response()->json([
            'message' => 'Profil berhasil diperbarui',
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'restaurant_id' => $admin->restaurant_id,
            ]
        ]);
    }

    // =========================================================
    // Mengubah password admin
    // =========================================================
    public function updatePassword(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Cek password lama
        if (!Hash::check($validated['current_password'], $admin->password)) {
            return response()->json([
                'message' => 'Password lama tidak sesuai.'
            ], 422);
        }

        // Simpan password baru
        $admin->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Password berhasil diubah.'
        ]);
    }
}