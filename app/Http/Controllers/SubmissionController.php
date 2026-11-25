<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    public function store(Request $request, Assignment $assignment): RedirectResponse
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        if (!Auth::user()->isMahasiswa()) {
            abort(403, 'Akses ditolak. Pengumpulan tugas hanya dapat dilakukan oleh Mahasiswa.');
        }

        $user = Auth::user();

        $enrollment = $assignment->course->students()->where('users.id', $user->id)->first();
        if (!$enrollment || $enrollment->pivot->status !== 'approved') {
            abort(403, 'Akses ditolak. Anda belum disetujui oleh Dosen untuk mengikuti mata kuliah tugas ini.');
        }

        if ($assignment->isOverdue()) {
            return back()->with('error', 'Gagal mengumpulkan: Batas waktu pengumpulan (deadline) tugas telah berakhir.');
        }

        $validated = $request->validate([
            'jawaban' => ['required', 'string', 'min:10', 'max:20000'],
        ], [
            'jawaban.required' => 'Teks jawaban tugas wajib diisi.',
            'jawaban.min' => 'Jawaban terlalu pendek. Tuliskan jawaban minimal 10 karakter.',
            'jawaban.max' => 'Jawaban melebihi batas maksimal 20.000 karakter.',
        ]);

        Submission::updateOrCreate(
            [
                'assignment_id' => $assignment->id,
                'user_id' => $user->id,
            ],
            [
                'jawaban' => $validated['jawaban'],
                'submitted_at' => now(),
            ]
        );

        return back()->with('success', 'Jawaban tugas Anda berhasil disimpan ke sistem.');
    }

    public function grade(Request $request, Submission $submission): RedirectResponse
    {
        // TODO 5a (grade): hanya dosen pengampu yang boleh menilai
        if ($submission->assignment->course->dosen_id !== Auth::id()) {
            abort(403, 'Akses ditolak. Anda bukan dosen pengampu mata kuliah tugas ini.');
        }

        $validated = $request->validate([
            'nilai' => ['required', 'integer', 'min:0', 'max:100'],
            'catatan_dosen' => ['nullable', 'string', 'max:1000'],
        ], [
            'nilai.required' => 'Nilai wajib diisi.',
            'nilai.integer' => 'Nilai harus berupa angka bulat.',
            'nilai.min' => 'Nilai minimal 0.',
            'nilai.max' => 'Nilai maksimal 100.',
            'catatan_dosen.max' => 'Catatan dosen maksimal 1000 karakter.',
        ]);

        $submission->update([
            'nilai' => $validated['nilai'],
            'catatan_dosen' => $validated['catatan_dosen'] ?? null,
        ]);

        return back()->with('success', 'Nilai untuk ' . $submission->user->name . ' berhasil disimpan.');
    }

    public function exportCsv(Assignment $assignment): StreamedResponse
    {
        // TODO 5b (exportCsv): hanya dosen pengampu yang boleh mengekspor
        if ($assignment->course->dosen_id !== Auth::id()) {
            abort(403, 'Akses ditolak. Anda bukan dosen pengampu mata kuliah tugas ini.');
        }

        $assignment->load(['course.approvedStudents', 'submissions']);

        $course = $assignment->course;
        $students = $course->approvedStudents->sortBy('name')->values();
        $submissions = $assignment->submissions->keyBy('user_id');
        $average = $submissions->whereNotNull('nilai')->avg('nilai');

        $filename = 'rekap-nilai-' . $course->kode_mk . '-tugas-' . $assignment->id . '.csv';

        // Cegah CSV/formula injection pada sel teks bebas (Excel menganggap awalan = + - @ sebagai rumus)
        $safe = static function (?string $value): string {
            $value = (string) $value;
            return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
        };

        return response()->streamDownload(function () use ($assignment, $course, $students, $submissions, $average, $safe) {
            $out = fopen('php://output', 'w');
            $d = ';'; // pemisah titik koma: terbaca rapi di Excel dengan regional Indonesia

            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 agar karakter terbaca benar di Excel

            fputcsv($out, ['Mata Kuliah', $course->kode_mk . ' - ' . $course->nama_mk], $d);
            fputcsv($out, ['Semester', $course->semester], $d);
            fputcsv($out, ['Tugas', $assignment->judul], $d);
            fputcsv($out, [], $d);

            fputcsv($out, ['No', 'Nama Mahasiswa', 'NIM', 'Email', 'Status Pengumpulan', 'Waktu Pengumpulan', 'Nilai', 'Status Nilai', 'Catatan Dosen'], $d);

            foreach ($students as $i => $student) {
                $sub = $submissions->get($student->id);

                fputcsv($out, [
                    $i + 1,
                    $safe($student->name),
                    $student->nomor_induk ?? '-',
                    $student->email,
                    $sub ? 'Sudah Mengumpulkan' : 'Belum Mengumpulkan',
                    $sub ? ($sub->submitted_at ?? $sub->updated_at)->format('d-m-Y H:i') : '-',
                    $sub && $sub->nilai !== null ? $sub->nilai : '-',
                    $sub ? $sub->status_nilai : '-',
                    $sub && $sub->catatan_dosen ? $safe($sub->catatan_dosen) : '-',
                ], $d);
            }

            fputcsv($out, [], $d);
            fputcsv($out, ['', '', '', '', '', 'Rata-rata Nilai', $average !== null ? number_format($average, 1, ',', '') : '-'], $d);

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}