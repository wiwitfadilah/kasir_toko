<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DetilPenjualan;
use App\Models\Pelanggan;
use App\Models\Penjualan;
use App\Models\Produk;
use App\Models\User;
use Cart;
use Illuminate\Support\Facades\Log;

class TransaksiController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->search;

        $penjualans = Penjualan::join('users', 'users.id', 'penjualans.user_id')
            ->leftjoin('pelanggans', 'pelanggans.id', 'penjualans.pelanggan_id')
            ->select('penjualans.*', 'users.nama as nama_kasir', 'pelanggans.nama as nama_pelanggan')
            ->orderBy('id', 'desc')
            ->when($search, function ($q, $search) {
                return $q->where('nomor_transaksi', 'like', "%{$search}%");
            })
            ->paginate();

        if ($search) $penjualans->appends(['search' => $search]);
        return view('transaksi.index', [
            'penjualans' => $penjualans
        ]);
    }

    public function create(Request $request)
    {
        return view('transaksi.create', [
            'nama_kasir' => $request->user()->nama,
            'tanggal' => date('d F Y')
        ]);
    }

    public function store(Request $request)
    {
        // Validasi request
        $request->validate([
            'pelanggan_id' => ['nullable', 'exists:pelanggans,id'],
            'cash' => ['required', 'numeric', 'gte:total_bayar'],
        ], [], ['pelanggan_id' => 'pelanggan']);
    
        if ($request->pelanggan_id) {
            $pelanggan = Pelanggan::find($request->pelanggan_id);
            $pelanggan->increment('poin', 1);
        }

        // Mendapatkan data pengguna dan transaksi terakhir
        $user = $request->user();
        $lastPenjualan = Penjualan::orderBy('id', 'desc')->first();

        // Mendapatkan detail keranjang belanja
        $cart = Cart::name($user->id);
        $cartDetails = $cart->getDetails();

        // Memeriksa stok produk sebelum melanjutkan transaksi
        $allItems = $cartDetails->get('items');
        foreach ($allItems as $key => $value) {
            $item = $allItems->get($key);
            $produk = Produk::find($item->id);
            // Pengecekan stok produk
            if ($produk->stok < $item->quantity) {
                return redirect()->back()->with('error', 'Stok produk ' . $produk->nama_produk . ' tidak mencukupi.');
            }
        }

        // Menghitung total, kembalian, dan nomor transaksi
        $total = $cartDetails->get('total');
        $kembalian = $request->cash - $total;
        $no = $lastPenjualan ? $lastPenjualan->id + 1 : 1;
        $no = sprintf("%04d", $no);

        // Membuat transaksi baru
        $penjualan = Penjualan::create([
            'user_id' => $user->id,
            'pelanggan_id' => $cart->getExtraInfo('pelanggan.id'),
            'nomor_transaksi' => date('Ymd') . $no,
            'tanggal' => date('Y-m-d H:i:s'),
            'total' => $total,
            'tunai' => $request->cash,
            'kembalian' => $kembalian,
            'pajak' => $cartDetails->get('tax_amount'),
            'subtotal' => $cartDetails->get('subtotal')
        ]);

        // Mengurangi stok produk dan membuat detail transaksi
        foreach ($allItems as $key => $value) {
            $item = $allItems->get($key);
            $produk = Produk::find($item->id);
            $produk->update(['stok' => $produk->stok - $item->quantity]);

            DetilPenjualan::create([
                'penjualan_id' => $penjualan->id,
                'produk_id' => $item->id,
                'jumlah' => $item->quantity,
                'harga_produk' => $item->price,
                'subtotal' => $item->subtotal,
            ]);
        }

        // Menghapus keranjang belanja setelah transaksi
        $cart->destroy();

        return redirect()->route('transaksi.show', ['transaksi' => $penjualan->id]);
    }
    
    public function show(Request $request, Penjualan $transaksi)
    {
        $pelanggan = Pelanggan::find($transaksi->pelanggan_id);
        $user = User::find($transaksi->user_id);
        $detilPenjualan = DetilPenjualan::join('produks', 'produks.id', 'detil_penjualans.produk_id')
            ->select('detil_penjualans.*', 'nama_produk')
            ->where('penjualan_id', $transaksi->id)->get();

        return view('transaksi.invoice', [
            'penjualan' => $transaksi,
            'pelanggan' => $pelanggan,
            'user' => $user,
            'detilPenjualan' => $detilPenjualan
        ]);
    }

    public function destroy(Request $request, Penjualan $transaksi)
    {
        $detailPenjualans = DetilPenjualan::query()->where('penjualan_id', $transaksi->id)->orderBy('id', 'desc')->get();
        foreach ($detailPenjualans as $detail) {
            $product = Produk::find($detail->produk_id);
            if ($product) {
                $product->update([
                    'stok' => $product->stok + $detail->jumlah,
                ]);
            }
        }

        $transaksi->update([
            'status' => 'batal'
        ]);

        return back()->with('destroy', 'success');
    }

    public function produk(Request $request)
    {
        $search = $request->search;
        $produks = Produk::select('id', 'kode_produk', 'nama_produk')
            ->when($search, function ($q, $search) {
                return $q->where('nama_produk', 'like', "%{$search}%");
            })
            ->orderBy('nama_produk')
            ->take(15)
            ->get();

        return response()->json($produks);
    }

    public function pelanggan(Request $request)
    {
        $search = $request->search;
        $pelanggans = Pelanggan::select('id', 'nama')
            ->when($search, function ($q, $search) {
                return $q->where('nama', 'like', "%{$search}%");
            })
            ->orderBy('nama')
            ->take(15)
            ->get();

        return response()->json($pelanggans);
    }

    public function addPelanggan(Request $request)
    {
        $request->validate([
            'id' => ['required', 'exists:pelanggans']
        ]);
        $pelanggan = Pelanggan::find($request->id);

        $cart = Cart::name($request->user()->id);

        $cart->setExtraInfo([
            'pelanggan' => [
                'id' => $pelanggan->id,
                'nama' => $pelanggan->nama,
            ]
        ]);

        return response()->json(['message' => 'Berhasil.']);
    }

    public function cetak(Penjualan $transaksi)
    {
        $pelanggan = Pelanggan::find($transaksi->pelanggan_id);
        $user = User::find($transaksi->user_id);
        $detilPenjualan = DetilPenjualan::join('produks', 'produks.id', 'detil_penjualans.produk_id')
            ->select('detil_penjualans.*', 'nama_produk')
            ->where('penjualan_id', $transaksi->id)->get();

        return view('transaksi.cetak', [
            'penjualan' => $transaksi,
            'pelanggan' => $pelanggan,
            'user' => $user,
            'detilPenjualan' => $detilPenjualan
        ]);
    }
}
