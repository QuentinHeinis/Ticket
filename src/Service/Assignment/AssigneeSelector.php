<?php

declare(strict_types=1);

namespace App\Service\Assignment;

final class AssigneeSelector
{
    /**
     * @param list<UserLoad> $loads
     */
    public function select(array $loads): ?UserLoad
    {
        $candidates = array_values(array_filter(
            $loads,
            static fn (UserLoad $load): bool => $load->hasRoom(),
        ));

        if ([] === $candidates) {
            return null;
        }

        usort($candidates, static function (UserLoad $a, UserLoad $b): int {
            $byLoad = $a->load <=> $b->load;

            if (0 !== $byLoad) {
                return $byLoad;
            }

            $aDate = $a->lastAssignedAt;
            $bDate = $b->lastAssignedAt;

            if (null === $aDate && null !== $bDate) {
                return -1;
            }

            if (null !== $aDate && null === $bDate) {
                return 1;
            }

            if (null !== $aDate && null !== $bDate) {
                $byDate = $aDate <=> $bDate;

                if (0 !== $byDate) {
                    return $byDate;
                }
            }

            return $a->userId <=> $b->userId;
        });

        return $candidates[0];
    }
}
