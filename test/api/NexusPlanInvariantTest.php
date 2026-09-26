<?php

require_once __DIR__ . '/../../backend/api/vendor/autoload.php';
require_once __DIR__ . '/../../backend/api/nexus/nexus_nlu.php';
require_once __DIR__ . '/../../backend/api/nexus/nexus_semantic.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NexusPlanInvariantTest extends TestCase
{
    private static function students(): array
    {
        return ['capability'=>'students.list', 'entity'=>'students', 'op'=>'list',
            'filters'=>['group'=>'10-A'], 'sort'=>null, 'position'=>null,
            'projection'=>null, 'presentation'=>null, 'cardinality'=>null];
    }

    private static function field(int $step = 0): array
    {
        return ['capability'=>'students.field', 'op'=>'field',
            'filters'=>['student'=>'@ref', 'field'=>'documento'],
            '_ref'=>['step'=>$step, 'pos'=>1]];
    }

    public function testValidReadPlanAndBackwardDependency(): void
    {
        self::assertSame([true, null], nxPlanValidate(self::students()));
        self::assertSame([true, null], nxPlanValidate([
            'capability'=>'composed', 'steps'=>[self::students(), self::field()],
        ]));
    }

    #[DataProvider('invalidPlans')]
    public function testInvalidPlansFailBeforeExecution(array $plan, string $reason): void
    {
        [$ok, $failure] = nxPlanValidate($plan);
        self::assertFalse($ok, json_encode($plan));
        self::assertStringContainsString($reason, (string)$failure);
    }

    public static function invalidPlans(): array
    {
        $base = self::students();
        return [
            'unknown capability' => [['capability'=>'invented.read'], 'unsupported_operation'],
            'required student' => [['capability'=>'guardian.of_student', 'filters'=>[]], 'missing_parameter:student'],
            'required field' => [['capability'=>'students.field', 'filters'=>['student'=>'Ana']], 'missing_parameter:field'],
            'arbitrary delegation' => [$base + ['_delegate_intent'=>'audit_query'], 'invalid_executor'],
            'arbitrary executor' => [$base + ['exec'=>'intent:audit_query'], 'invalid_executor'],
            'non read effect' => [$base + ['effect'=>'DELETE'], 'non_read_operation'],
            'unknown operation' => [array_replace($base, ['op'=>'delete']), 'unsupported_operation'],
            'invalid filters type' => [array_replace($base, ['filters'=>'10-A']), 'invalid_parameter:filters'],
            'orphan reference' => [self::field(), 'invalid_reference'],
            'unbound placeholder' => [['capability'=>'students.field', 'filters'=>['student'=>'@ref', 'field'=>'nombre']], 'invalid_reference'],
            'empty composition' => [['capability'=>'composed', 'steps'=>[]], 'invalid_plan'],
            'malformed steps' => [['capability'=>'composed', 'steps'=>'bad'], 'invalid_plan'],
            'non plan step' => [['capability'=>'composed', 'steps'=>[self::students(), null]], 'invalid_plan'],
            'forward reference' => [['capability'=>'composed', 'steps'=>[self::field(1), self::students()]], 'invalid_reference'],
            'self reference' => [['capability'=>'composed', 'steps'=>[self::students(), self::field(1)]], 'invalid_reference'],
            'missing step' => [['capability'=>'composed', 'steps'=>[self::students(), self::field(12)]], 'invalid_reference'],
            'fractional position' => [array_replace($base, ['position'=>1.5]), 'invalid_parameter:position'],
            'unknown position' => [array_replace($base, ['position'=>'random']), 'invalid_parameter:position'],
            'zero slice' => [$base + ['slice'=>['n'=>0, 'from'=>'start']], 'invalid_parameter:slice'],
            'fractional slice' => [$base + ['slice'=>['n'=>2.5, 'from'=>'start']], 'invalid_parameter:slice'],
            'unknown slice direction' => [$base + ['slice'=>['n'=>2, 'from'=>'middle']], 'invalid_parameter:slice'],
            'comparison requires first group' => [['capability'=>'groups.compare', 'filters'=>['group2'=>'10-B']], 'missing_parameter:group'],
        ];
    }

    #[DataProvider('referencePositions')]
    public function testReferencePositionsAreValidated(mixed $position, bool $valid): void
    {
        $field = self::field();
        $field['_ref']['pos'] = $position;
        [$ok] = nxPlanValidate(['capability'=>'composed', 'steps'=>[self::students(), $field]]);
        self::assertSame($valid, $ok);
    }

    public static function referencePositions(): array
    {
        return [[1,true], [3,true], [-1,true], [-2,true], ['each',true],
            [0,false], [-3,false], [1.5,false], ['first',false], [null,false]];
    }

    public function testDependenciesAreNotLimitedToTheFirstStep(): void
    {
        $second = self::students();
        $second['filters']['group'] = '10-B';
        self::assertSame([true, null], nxPlanValidate([
            'capability'=>'composed', 'steps'=>[self::students(), $second, self::field(1)],
        ]));
    }
}
