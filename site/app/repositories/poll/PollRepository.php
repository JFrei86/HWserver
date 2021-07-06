<?php

declare(strict_types=1);

namespace app\repositories\poll;

use Doctrine\ORM\EntityRepository;

class PollRepository extends EntityRepository
{
    /**
     * @return \app\entities\poll\Poll[]
    */
    public function findByToday(): array {
        return $this->_em
            ->createQuery(
                'SELECT p FROM app\entities\poll\Poll p WHERE p.release_date = :release_date ORDER BY p.name ASC'
            )
            ->setParameter('release_date', date('Y-m-d'))
            ->getResult();
    }

    /**
     * @return \app\entities\poll\Poll[]
    */
    public function findByOld() {
        return $this->_em
            ->createQuery(
                'SELECT p FROM app\entities\poll\Poll p WHERE p.release_date < :release_date ORDER BY p.name ASC'
            )
            ->setParameter('release_date', date('Y-m-d'))
            ->getResult();
    }

    /**
     * @return \app\entities\poll\Poll[]
    */
    public function findByFuture () {
        return $this->_em
            ->createQuery(
                'SELECT p FROM app\entities\poll\Poll p WHERE p.release_date > :release_date ORDER BY p.name ASC'
            )
            ->setParameter('release_date', date('Y-m-d'))
            ->getResult();
    }
}
