<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use Tests\TestCase;

class ArabicTranslationsTest extends TestCase
{
    public function test_every_validation_message_of_the_framework_exists_in_arabic(): void
    {
        $english = Arr::dot(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'));
        $arabic = Arr::dot(require base_path('lang/ar/validation.php'));

        $missing = array_diff(array_keys($english), array_keys($arabic));
        $missing = array_filter($missing, fn ($key) => ! str_starts_with($key, 'custom.') && ! str_starts_with($key, 'attributes'));

        $this->assertSame([], array_values($missing), 'Arabic validation messages are missing');
    }

    public function test_every_own_translation_file_has_the_same_keys_in_arabic_and_english(): void
    {
        foreach (glob(base_path('lang/en/*.php')) as $file) {
            $name = basename($file);
            $this->assertFileExists(base_path("lang/ar/$name"));
            $en = array_keys(Arr::dot(require $file));
            $ar = array_keys(Arr::dot(require base_path("lang/ar/$name")));

            $this->assertSame([], array_values(array_diff($en, $ar)), "$name: keys missing in Arabic");
            $this->assertSame([], array_values(array_diff($ar, $en)), "$name: keys missing in English");
        }
    }
}
