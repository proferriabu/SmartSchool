<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AbsensiTest extends Model
{
    use HasFactory;

    protected $fillable = [
        'status_absen',
        'role',
        'id_user',
        'created_at'
    ];

    public function guru()
    {
        return $this->belongsTo(Guru::class, 'id_user', 'id');
    }

    public function siswa()
    {
        return $this->belongsTo(Siswa::class, 'id_user', 'id');
    }
}
