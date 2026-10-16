<?php

namespace App\Http\Controllers;

use App\Jobs\ImportContactsJob;
use App\Models\CsvImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** JSON endpoints used by the import dialog: upload → preview/map → start → poll. */
class CsvImportController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:20480']]);

        $file = $request->file('file');
        $path = $file->store('imports');

        $handle = fopen(Storage::path($path), 'r');
        $headers = array_map(fn ($h) => trim($h, " \t\n\r\0\x0B\xEF\xBB\xBF"), fgetcsv($handle) ?: []);
        $rows = [];
        $total = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue;
            }
            if ($total < 10) {
                $rows[] = $row;
            }
            $total++;
        }
        fclose($handle);

        if (! $headers) {
            return response()->json(['message' => 'The file is empty.', 'errors' => ['file' => ['The file is empty.']]], 422);
        }

        $import = CsvImport::create([
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'total_rows' => $total,
        ]);

        return response()->json(['id' => $import->id, 'headers' => $headers, 'rows' => $rows, 'total_rows' => $total]);
    }

    public function start(Request $request, CsvImport $import)
    {
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        if (! in_array('email', $data['mapping'], true)) {
            return response()->json(['message' => 'Map a column to Email.', 'errors' => ['mapping' => ['Map a column to Email.']]], 422);
        }

        $import->update(['mapping' => $data['mapping'], 'tags' => array_values(array_filter($data['tags'] ?? [])), 'status' => 'pending']);
        ImportContactsJob::dispatch($import);

        return response()->json($import->fresh());
    }

    public function show(CsvImport $import)
    {
        return response()->json($import);
    }
}
