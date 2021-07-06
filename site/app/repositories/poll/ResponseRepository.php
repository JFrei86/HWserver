<?php

declare(strict_types=1);

namespace app\repositories\poll;

use Doctrine\ORM\EntityRepository;

class ResponseRepository extends EntityRepository
{
    /**
     * @return \app\entities\poll\Response[]
    */
    public function findByPollIdAndStudentId(int $poll_id, string $student_id): array {
        return $this->_em
            ->createQuery(
                'SELECT r FROM app\entities\poll\Response r WHERE r.poll_id = :poll_id AND r.student_id = :student_id'
            )
            ->setParameter('poll_id', $poll_id)
            ->setParameter('student_id', $student_id)
            ->getResult();
    }
}
