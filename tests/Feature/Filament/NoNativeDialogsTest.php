<?php

namespace Tests\Feature\Filament;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every confirmation / input dialog goes through RencanakanAlert (SweetAlert2).
 * A bare window.prompt() / confirm() in a Blade view brings back the native
 * browser dialog this panel replaced.
 */
class NoNativeDialogsTest extends TestCase
{
    public function test_no_blade_view_calls_a_native_prompt_or_confirm(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            // Blade comments may mention confirm()-gated controls.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            if (preg_match('/(?<![\w.$>])(?:window\.)?(?:prompt|confirm|alert)\s*\(/', $source)) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Use RencanakanAlert.prompt / confirm / modal instead.');
    }

    public function test_the_sweetalert_helpers_exist_in_the_bundle_source(): void
    {
        $js = file_get_contents(resource_path('js/filament.js'));

        $this->assertStringContainsString('window.RencanakanAlert = { confirm: rkConfirm, toast: rkToast, prompt: rkPrompt, modal: rkModal, Swal };', $js);
    }
}
