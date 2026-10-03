<?php

// Run separately without a Composer autoloader: this intentionally has no Intervention.
require __DIR__.'/../../src/Form/Field/ImageProcessor.php';
require __DIR__.'/../../src/Form/Field/ImageField.php';
class OptionalImageHarness
{
    use \Encore\Admin\Form\Field\ImageField;
    public static function hasMacro($method) { return false; }
}
$field = new OptionalImageHarness();
if ($field->callInterventionMethods('/unread/ordinary-upload') !== '/unread/ordinary-upload') {
    throw new RuntimeException('Ordinary image upload changed without image processing.');
}
foreach ([fn () => $field->rotate(90), fn () => $field->imageProcessing(fn ($image) => $image), fn () => $field->thumbnail('small', 10, 10)->callInterventionMethods('/unread/ordinary-upload')] as $operation) {
    try {
        $operation();
        throw new RuntimeException('Missing optional dependency was not rejected.');
    } catch (LogicException $exception) {
        if (!str_contains($exception->getMessage(), 'intervention/image ^3.11.9')) {
            throw $exception;
        }
    }
}
echo "Optional image dependency boundary passed.\n";
