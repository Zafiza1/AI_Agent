<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Str;

class Slug
{
    /**
     * Build a slug from $value that does not collide according to $exists.
     *
     * @param  Closure(string): bool  $exists
     */
    public static function unique(string $value, Closure $exists): string
    {
        $base = Str::slug($value) ?: 'item';
        $slug = $base;

        for ($i = 2; $exists($slug); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
