<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Every Blade view compiles to valid PHP. Catches a quote inside __('...') (shop's) or a directive glued to a word
 * (@endif@include), which page tests only find when that exact branch renders (feedback 2026-11-22: checkout
 * prices failed with "Check your connection" because the summary view did not compile).
 */
class ViewsCompileTest extends TestCase
{
    public function test_all_views_compile_to_valid_php(): void
    {
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $f) {
            if (! str_ends_with($f->getFilename(), '.blade.php')) {
                continue;
            }
            $php = Blade::compileString(file_get_contents($f->getPathname()));
            try {
                token_get_all($php, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $bad[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $f->getPathname()).': '.$e->getMessage();
            }
            // a directive glued to a word is printed as text instead of running
            if (preg_match('/[A-Za-z0-9_]@(include|if|endif|else|elseif|foreach|endforeach|php|csrf)\b/', file_get_contents($f->getPathname()), $m)) {
                $bad[] = $f->getFilename().': directive glued to text near "'.$m[0].'"';
            }
        }
        $this->assertSame([], $bad);
    }
}
