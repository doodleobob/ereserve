<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class FormValidationPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function export(string $name, string $html): void
    {
        if ($directory = getenv('VALIDATION_RENDER_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }

    public function test_login_errors_keep_field_names_and_old_values(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['email' => 'Email is not recognized.', 'password' => 'Password is required.']));
        $response = $this->withSession(['errors' => $errors, '_old_input' => ['email' => 'resident@example.test']])->get(route('login'))->assertOk()
            ->assertSee('data-validation-field="email"', false)->assertSee('data-validation-field="password"', false)
            ->assertSee('value="resident@example.test"', false)->assertSee('js/form-validation.js');
        $this->export('login-errors', $response->getContent());
    }

    public function test_security_errors_stay_in_the_security_form_and_preserve_the_named_bag(): void
    {
        $user = User::factory()->create();
        $errors = (new ViewErrorBag)->put('security', new MessageBag(['current_password' => 'Security password is incorrect.']));
        $response = $this->actingAs($user)->withSession(['errors' => $errors])->get(route('profile.edit'))->assertOk();
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//form//p[@data-validation-field="current_password"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="security"]//form//p[@data-validation-field="current_password"]')->length);
        $this->assertSame(1, substr_count($response->getContent(), 'Security password is incorrect.'));
        $this->export('security-errors', $response->getContent());
    }
}
