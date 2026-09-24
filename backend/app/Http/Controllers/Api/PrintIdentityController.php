<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PrintIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PrintIdentityController extends Controller
{
    public function show(PrintIdentityService $service)
    {
        return $this->success($service->editable());
    }

    public function update(Request $request, PrintIdentityService $service)
    {
        $request->validate([
            'payload' => ['required', 'string'],
            'logo' => ['nullable', 'file', 'max:2048', 'extensions:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp'],
        ]);
        $payload = json_decode($request->string('payload')->toString(), true);
        if (! is_array($payload)) abort(422, 'Print identity payload must be valid JSON.');
        $rules = [
            'fields' => ['required', 'array'],
            'fields.business_name.value' => ['required', 'string', 'max:255'],
            'logo_visible' => ['required', 'boolean'], 'logo_order' => ['required', 'integer', 'min:0', 'max:999'],
            'remove_logo' => ['sometimes', 'boolean'], 'custom_fields' => ['present', 'array', 'max:20'],
            'custom_fields.*.label' => ['required', 'string', 'max:100'], 'custom_fields.*.value' => ['required', 'string', 'max:500'],
            'custom_fields.*.visible' => ['required', 'boolean'], 'custom_fields.*.order' => ['required', 'integer', 'min:0', 'max:999'],
        ];
        foreach (PrintIdentityService::FIELD_KEYS as $key) {
            $rules["fields.$key.value"] = [$key === 'business_name' ? 'required' : 'nullable', 'string', 'max:'.($key === 'address' ? 500 : 255)];
            $rules["fields.$key.visible"] = ['required', 'boolean'];
            $rules["fields.$key.order"] = ['required', 'integer', 'min:0', 'max:999'];
        }
        $rules['fields.email.value'] = ['nullable', 'email:rfc', 'max:255'];
        $rules['fields.website.value'] = ['nullable', 'url:http,https', 'max:255'];
        foreach (['phone', 'secondary_phone', 'mobile'] as $key) $rules["fields.$key.value"] = ['nullable', 'string', 'max:50', 'regex:/^[\pL\pN\s+().\-\/]*$/u'];
        $validated = Validator::make($payload, $rules)->validate();
        return $this->success($service->update($validated, $request->file('logo'), $request->user()->id));
    }

    public function logo(string $asset, PrintIdentityService $service)
    {
        $file = $service->asset($asset);
        abort_unless($file && Storage::disk($file->disk)->exists($file->storage_key), 404);
        return Storage::disk($file->disk)->response($file->storage_key, null, [
            'Content-Type' => $file->mime_type, 'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
