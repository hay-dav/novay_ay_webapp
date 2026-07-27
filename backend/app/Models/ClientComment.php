<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientComment extends Model
{
    protected $fillable = ['client_id', 'author_id', 'body'];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }
}
