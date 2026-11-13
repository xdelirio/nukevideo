<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;

class TemplateAudioRule implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $codecs = collect(config('ffmpeg.codecs'))->keyBy('codec');
        $parameters = config('ffmpeg.parameters');

        $audioCodecKey = $value['audio_codec'] ?? null;

        if (! $audioCodecKey || ! $codecs->has($audioCodecKey)) {
            $fail("The audio codec '{$audioCodecKey}' is invalid.");

            return;
        }

        $audioCodec = $codecs->get($audioCodecKey);
        if ($audioCodec['type'] !== 'audio') {
            $fail("The codec '{$audioCodecKey}' is not an audio codec.");

            return;
        }

        $audioParameters = collect($parameters)
            ->filter(function ($param, $key) use ($audioCodecKey) {
                return $param['type'] === 'audio'
                    && in_array($audioCodecKey, $param['available_for'] ?? [])
                    && ! in_array($key, ['channels', 'audio_bitrate']);
            })
            ->toArray();

        $this->validateParameters($value, $audioParameters, $fail);

        $channels = $value['channels'] ?? [];
        if (! is_array($channels) || empty($channels)) {
            $fail('At least one audio channel configuration is required.');

            return;
        }

        $channelRules = collect($parameters)
            ->filter(function ($param, $key) use ($audioCodecKey) {
                return $param['type'] === 'audio'
                    && in_array($audioCodecKey, $param['available_for'] ?? [])
                    && in_array($key, ['channels', 'audio_bitrate']);
            })
            ->toArray();

        foreach ($channels as $index => $channelConfig) {
            if (! is_array($channelConfig)) {
                $fail("Channel configuration at index {$index} is invalid.");

                continue;
            }

            // A rung is a channel count and its bitrate. Anything else in it is merged over the
            // audio block at encode time without having been validated here — a codec of its
            // own, most of all.
            if (array_diff(array_keys($channelConfig), ['channels', 'audio_bitrate']) !== []) {
                $fail("Channel configuration at index {$index} may only set channels and audio_bitrate.");

                continue;
            }

            $this->validateParameters($channelConfig, $channelRules, $fail);
        }
    }

    protected function validateParameters(array $data, array $parameters, Closure $fail): void
    {
        $rules = [];
        $attributes = [];

        foreach ($parameters as $paramKey => $config) {
            if (isset($config['rules'])) {
                $rules[$paramKey] = $config['rules'];
                $attributes[$paramKey] = $config['label'] ?? $paramKey;
            }
        }

        $validator = Validator::make($data, $rules, [], $attributes);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $fail($message);
            }
        }
    }
}
