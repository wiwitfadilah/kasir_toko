<?php

namespace App\Http\Controllers;

use App\Models\Pelanggan;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

class PelangganController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->search;
        $query = Pelanggan::orderBy('id');

        if ($search) {
            $query->where('nama', 'like', "%{$search}%");
        }

        // Filter pelanggan hanya yang memiliki status "Member"
        $pelanggans = $query->where('status_member', 'Member')->paginate();

        return view('pelanggan.index', compact('pelanggans'));
    }


    public function create()
    {
        return view('pelanggan.create');
    }





    public function show(Pelanggan $pelanggan)
    {
        abort(404);
    }

    public function edit(Pelanggan $pelanggan)
    {
        return view('pelanggan.edit', compact('pelanggan'));
    }

    public function update(Request $request, Pelanggan $pelanggan)
    {
        $request->validate([
            'nama' => ['required', 'max:100'],
            'alamat' => ['nullable', 'max:500'],
            'nomor_tlp' => ['nullable', 'max:14']
        ]);

        $pelanggan->update($request->all());

        return redirect()->route('pelanggan.index')->with('update', 'success');
    }

    public function destroy(Pelanggan $pelanggan)
    {
        $pelanggan->delete();

        return back()->with('destroy', 'success');
    }
}