<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Exception\ValidationException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Settings\SettingDefinition;

final class AdminSettingsController extends AdminController
{
    protected function section(): string
    {
        return 'settings';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        return $this->renderModule($context, [], null);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $settings = $this->settings();
        $module = $this->currentModule($context);

        $values = [];
        foreach ($settings->registry()->forModule($module) as $key => $definition) {
            $field = $this->fieldName($key);
            if ($definition->type === \SecondStay\Settings\SettingType::Bool) {
                $values[$key] = $context->request->input($field) !== null;
                continue;
            }
            $raw = $context->request->input($field);
            if ($raw === null) {
                continue;
            }
            $values[$key] = $raw;
        }

        try {
            $settings->setMany($values, $user->email, $user->id);
        } catch (ValidationException $exception) {
            return $this->renderModule($context, $exception->errors(), $module, 422);
        }

        $this->flashSuccess('admin.settings.saved');

        return $this->redirect(
            $context->request->basePath
            . $this->router()->path('admin.settings', ['module' => $module], $context->locale)
        );
    }

    private function currentModule(RequestContext $context): string
    {
        $registry = $this->settings()->registry();
        $requested = $context->request->query('module') ?? $context->request->input('module');
        $modules = $registry->modules();

        if (is_string($requested) && in_array($requested, $modules, true)) {
            return $requested;
        }

        return $modules[0] ?? 'property';
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderModule(RequestContext $context, array $errors, ?string $module, int $status = 200): Response
    {
        $settings = $this->settings();
        $registry = $settings->registry();
        $module ??= $this->currentModule($context);

        $fields = [];
        foreach ($registry->forModule($module) as $key => $definition) {
            $fields[] = [
                'key' => $key,
                'field' => $this->fieldName($key),
                'type' => $definition->type->value,
                'input_type' => $definition->type->inputType(),
                'label' => $this->trans($definition->labelKey()),
                'help' => $this->trans($definition->helpTranslationKey()),
                'required' => $definition->required,
                'enum' => $definition->enumValues,
                'min' => $definition->min,
                'max' => $definition->max,
                'value' => $this->displayValue($definition),
                'secret_defined' => $definition->isSecret() && $settings->isSecretDefined($key),
                'secret_preview' => $definition->isSecret() ? $settings->secretPreview($key) : '',
                'error' => $errors[$key] ?? null,
            ];
        }

        return $this->renderAdmin('admin/settings.html.twig', [
            'meta_title' => $this->trans('admin.settings.title'),
            'modules' => $registry->modules(),
            'current_module' => $module,
            'fields' => $fields,
            'errors' => $errors,
        ], $status);
    }

    /**
     * Les montants sont stockés en centimes mais saisis en euros.
     */
    private function displayValue(SettingDefinition $definition): string
    {
        if ($definition->isSecret()) {
            return '';
        }

        $value = $this->settings()->get($definition->key);

        if ($definition->type === \SecondStay\Settings\SettingType::Money) {
            return number_format(((int) $value) / 100, 2, '.', '');
        }

        if ($definition->type === \SecondStay\Settings\SettingType::Bool) {
            return $value === true ? '1' : '0';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function fieldName(string $key): string
    {
        return 'setting_' . str_replace('.', '__', $key);
    }
}
