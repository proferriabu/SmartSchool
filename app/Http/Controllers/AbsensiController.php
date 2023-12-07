<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\Akademik;
use App\Models\Kelas;
use App\Models\User;
use App\Models\Keteranganabsensi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class AbsensiController extends Controller
{
    public function showAbsensiAdmin(){
        return view('pages.akademik.absensi.absensi-admin', [
            'absensis'=>Absensi::all()
        ])->with('title', 'Absensi Admin');
    }

    public function deleteAbsensi($id) {
        try {
            // Lakukan penghapusan data absensi berdasarkan ID
            Absensi::findOrFail($id)->delete();
    
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            // Tangani kesalahan jika terjadi
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    public function showAbsensiSiswa(Request $request)
{
    $absensis = Absensi::all();

    if ($request->ajax()) {
        return response()->json($absensis);
    }

    return view('pages.akademik.absensi.absensi-siswa', compact('absensis'))->with('title', 'Absensi Siswa');
}

public function store(Request $request)
{
    // Log data request
    Log::info('Absensi store request data:', $request->all());
    Log::info('Before creating Absensi:', [
        'status_absen' => $request->input('status_absen'),
        'role' => $request->input('role'),
        'id_user' => $request->input('id_user'),
    ]);

    // Validasi request
    $request->validate([
        'status_absen' => 'required|in:masuk,sakit,izin',
        'role' => 'required',
        'id_user' => 'required',
    ]);

    // Cek apakah pengguna telah melakukan presensi pada hari ini
    $userId = $request->input('id_user');
    $today = now()->format('Y-m-d');
    $absensi = Absensi::where('id_user', $userId)
                    ->whereDate('created_at', $today)
                    ->first();

    if ($absensi) {
        // Jika pengguna telah melakukan presensi pada hari ini, tampilkan pesan
        Log::info('Presensi hari ini sudah ada untuk user ' . $userId);
        return response()->json(['message' => 'Anda telah melakukan presensi pada hari ini'], 400);
    }

    // Buat data absensi dengan mengisi semua kolom yang diperlukan
    $absensi = new Absensi([
        'status_absen' => $request->input('status_absen'),
        'role' => $request->input('role'), // Set the role from the request
        'id_user' => $request->input('id_user'), // Set the user ID from the request
        'created_at' => now(),
    ]);

    Log::info('After creating Absensi:', $absensi->toArray());

    // Simpan data absensi ke database
    $absensi->save();

    return response()->json(['message' => 'Data absensi berhasil disimpan'], 201);
}


public function checkAndFillAbsentData()
{
    Log::info('checkAndFillAbsentData dijalankan pada ' . now());
    $userId = Auth::id();

    // Tentukan tanggal awal dan akhir untuk pengecekan
    $startDate = now()->setYear(2023)->setMonth(12)->setDay(1);
    $endDate = now()->subDay(); // Tanggal kemarin (sehari sebelum hari ini)

    $dataInserted = false; // Indikator apakah ada data tambahan yang dimasukkan

    // Looping untuk setiap tanggal
    while ($startDate <= $endDate) {
        // Periksa apakah sudah ada data absensi untuk tanggal ini
        $absensi = Absensi::where('id_user', $userId)
            ->whereDate('created_at', $startDate->format('Y-m-d'))
            ->first();

        // Jika belum ada data absensi, isi otomatis
        if (!$absensi) {
            $role = Auth::user()->role;
            $createdDate = $startDate->format('Y-m-d') . ' 16:00:00';

            Absensi::create([
                'status_absen' => 'tidak masuk',
                'role' => $role,
                'id_user' => $userId,
                'created_at' => $createdDate,
            ]);

            $dataInserted = true; // Set indikator bahwa ada data tambahan yang dimasukkan
        }

        // Tambahkan satu hari untuk lanjut ke tanggal berikutnya
        $startDate->addDay();
    }

    // Cek apakah sudah ada data absensi untuk hari ini
    $absensiToday = Absensi::where('id_user', $userId)
        ->whereDate('created_at', now()->format('Y-m-d'))
        ->first();

    // Jika belum ada data absensi untuk hari ini dan sudah lebih dari jam 16:00
    if (!$absensiToday && now()->format('H:i:s') >= '16:00:00') {
        $roleToday = Auth::user()->role;

        Absensi::create([
            'status_absen' => 'tidak masuk',
            'role' => $roleToday,
            'id_user' => $userId,
            'created_at' => now(),
        ]);

        $dataInserted = true; // Set indikator bahwa ada data tambahan yang dimasukkan

        // Aktifkan fungsi disablePresensiOption pada web page
        return response()->json(['success' => true, 'dataInserted' => $dataInserted, 'disablePresensiOption' => true]);
    }

    // Mengirim respons berdasarkan apakah ada data tambahan atau tidak
    return response()->json(['success' => true, 'dataInserted' => $dataInserted, 'disablePresensiOption' => false]);
}


public function tambahEvent(Request $request)
{
    $data = $request->validate([
        'tanggal' => 'required|date',
        'status' => 'required|in:weekend,libur',
        'keterangan' => 'nullable|string',
    ]);

    Keteranganabsensi::create($data);

    return redirect()->back()->with('success', 'Event berhasil ditambahkan.');
}

public function getEventsFromDatabase()
{
    Log::info('Attempting to fetch events from database...');
    try {
        // Mengambil semua data dari database
        $events = Keteranganabsensi::all();

        // Menyaring data untuk mendapatkan tanggal akhir pekan
        $weekendDates = $events->filter(function ($event) {
            return $event->status === 'weekend';
        })->pluck('tanggal')->toArray();

        Log::info('Filtered weekend dates:', $weekendDates);

        return response()->json($weekendDates);
    } catch (\Exception $e) {
        Log::error('Error fetching events from database: ' . $e->getMessage());
        return response()->json(['error' => 'Failed to fetch events from database'], 500);
    }
}



    public function index()
    {
        return view('pages.akademik.absensi.absensi', [
            'akademiks' => Akademik::groupBy('tahun_ajaran')->get(),
        ])->with('title', 'Absensi');
    }

    public function showKelasAbsensi(Request $request, $tahun_akademik, $kelas)
    {
        // return $request->selected_kelas;
        $tahun_akademik = str_replace('-', '/', $tahun_akademik);
        $kelas_list = Kelas::where('nama_kelas', 'LIKE', $kelas . ' %')->get();

        if ($request->has('selected_kelas') && $request->has('selected_semester')) {
            $akademik = Akademik::where('tahun_ajaran', $tahun_akademik)->where('semester', $request->selected_semester)->get();
            $absensis = Absensi::all()->where('id_akademik', $akademik->first()->id)->where('kelas', $request->selected_kelas);
        } else {
            $akademik = Akademik::where('tahun_ajaran', $tahun_akademik)->where('semester', 'ganjil')->get();
            $absensis = Absensi::all()->where('id_akademik', $akademik->first()->id)->where('kelas', $kelas_list->first()->id);
        }

        if (count($kelas_list) < 1 || count($akademik) < 1) {
            abort(404);
        }

        return view('pages.akademik.absensi.absensi-kelas', [
            'kelas_list' => $kelas_list,
            'selected_kelas' => $request->selected_kelas ?? null,
            'selected_semester' => $request->selected_semester ?? 'ganjil',
            'list_status' => ['tidak masuk', 'masuk', 'sakit', 'izin', 'telat'],
            'absensis' => $absensis,
        ])->with('title', 'Absensi');
    }

    public function apiUpdateAbsensi(Absensi $absensi, Request $request)
    {
        $absensi->update([
            'status_absen' => $request->status,
            'keterangan' => $request->status == 'izin' ? $request->keterangan_izin : '',
        ]);

        return back();
    }
}
