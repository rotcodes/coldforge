<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CsvImport extends Model
{
    protected $fillable = ['filename', 'path', 'status', 'total_rows', 'imported_count', 'failed_count', 'mapping', 'tags'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'tags' => 'array'];
    }
}
