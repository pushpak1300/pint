<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $casts = ['active' => 'boolean', 'price' => 'integer', 'display_name' => 'integer', 'score' => 'string'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function getDisplayNameAttribute(): string
    {
        return 'Example';
    }

    /** @return Attribute<int, never> */
    protected function score(): Attribute
    {
        return Attribute::make(get: fn (): int => 42);
    }

    /** @return Attribute<never, string> */
    protected function secret(): Attribute
    {
        return Attribute::set(fn (string $value): string => $value);
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends Model
{
    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

class InheritedUser extends User {}

/** @property-read int $active */
class DocumentedUser extends User {}

class DeclaredUser extends User
{
    public int $active = 1;
}

class PropertyUser extends Model
{
    protected $casts = ['active' => 'boolean'];
}

class DynamicUser extends User
{
    protected function casts(): array
    {
        return random_int(0, 1) ? ['price' => 'integer'] : ['price' => 'string'];
    }
}
