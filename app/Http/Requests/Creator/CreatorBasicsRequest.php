<?php

namespace App\Http\Requests\Creator;

use App\Enums\SocialPlatform;
use App\Services\Claims\SlugService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Onboarding step 4 and /dashboard/profile share these rules (plan §6.3).
 */
class CreatorBasicsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->creator !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => strtolower(trim((string) $this->input('slug'))),
            'socials' => array_values(array_filter((array) $this->input('socials', []), fn ($row) => filled($row['url'] ?? null))),
            'tags' => array_values(array_filter((array) $this->input('tags', []), 'filled')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creator = $this->user()?->creator;

        return [
            'display_name' => ['required', 'string', 'max:60'],
            'real_name' => ['nullable', 'string', 'max:80'],
            'real_name_am' => ['nullable', 'string', 'max:80'],
            'slug' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($creator) {
                $problem = app(SlugService::class)->problem((string) $value, $creator);

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
            'bio' => ['nullable', 'string', 'max:600'],
            'location' => ['nullable', 'string', 'max:80'],
            'timezone' => ['required', 'timezone:all'],
            'socials' => ['array', 'max:10'],
            'socials.*.url' => ['required', 'string', 'max:300', 'regex:/^(https?:\/\/)?[\w.-]+\.[a-z]{2,}(\/\S*)?$/i'],
            'socials.*.platform' => ['nullable', Rule::enum(SocialPlatform::class)],
            'tags' => ['array', 'max:5'],
            'tags.*' => ['string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'socials.*.url.regex' => 'That doesn\'t look like a link.',
            'tags.max' => 'Pick up to 5 tags.',
        ];
    }
}
