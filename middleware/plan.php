<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/response.php';

/**
 * Require a specific plan.
 *
 * Example:
 * require_plan($user, 'pro');
 */
function require_plan(
    array $user,
    string $requiredPlan
): void {

    $currentPlan = strtolower(
        (string)($user['plan'] ?? 'free')
    );

    $requiredPlan = strtolower(
        $requiredPlan
    );

    if ($requiredPlan === 'free') {
        return;
    }

    if (
        $requiredPlan === 'pro'
        && $currentPlan !== 'pro'
    ) {

        api_error(
            'This feature requires a Pro subscription.',
            403,
            [
                'required_plan' => 'pro',
                'current_plan' => $currentPlan
            ]
        );
    }
}
