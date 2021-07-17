<?php

declare(strict_types=1);

namespace app\entities\course;

use app\libraries\DateUtils;
use app\models\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class CourseMaterial
 * @package app\entities
 * @ORM\Entity
 * @ORM\Table(name="course_materials")
 */
class CourseMaterial {
    const FILE = 0;
    const LINK = 1;
    const DIR = 2;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     * @var integer
     */
    protected $id;

    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected $path;

    /**
     * @ORM\Column(type="smallint")
     * @var int
     */
    protected $type;

    /**
     * @ORM\Column(type="datetimetz")
     * @var \DateTime
     */
    protected $release_date;

    /**
     * @ORM\Column(type="boolean")
     * @var boolean
     */
    protected $hidden_from_students;

    /**
     * @ORM\Column(type="float")
     * @var float
     */
    protected $priority;

    /**
     * @ORM\OneToMany(targetEntity="\app\entities\course\CourseMaterialSection", mappedBy="course_material", fetch="EAGER", cascade={"persist"}, orphanRemoval=true)
     * @var Collection<CourseMaterialSection>
     */
    protected $sections;

    public function __construct(int $type, string $path, \DateTime $release_date, bool $hidden_from_students, float $priority) {
        $this->setType($type);
        $this->setPath($path);
        $this->setReleaseDate($release_date);
        $this->setHiddenFromStudents($hidden_from_students);
        $this->setPriority($priority);
        $this->sections = new ArrayCollection();
    }

    /**
     * @return Collection<CourseMaterialSection>
     */
    public function getSections(): Collection {
        return $this->sections;
    }

    public function getPath(): string {
        return $this->path;
    }

    public function getType(): int {
        return $this->type;
    }

    public function isFile(): bool {
        return $this->type === self::FILE;
    }

    public function isLink(): bool {
        return $this->type === self::LINK;
    }

    public function isDir(): bool {
        return $this->type === self::DIR;
    }

    public function getPriority(): float {
        return $this->priority;
    }

    public function getReleaseDate(): \DateTime {
        return $this->release_date;
    }

    public function isHiddenFromStudents(): bool {
        return $this->hidden_from_students;
    }

    public function isSectionAllowed(User $user): bool {
        if ($this->sections->count() === 0) {
            return true;
        }
        foreach ($this->sections->getValues() as $course_material_section) {
            if ($user->getRegistrationSection() === $course_material_section->getSectionId()) {
                return true;
            }
        }
        return false;
    }

    public function setReleaseDate(\DateTime $release_date): void {
        $this->release_date = $release_date;
    }

    public function setHiddenFromStudents(bool $hidden_from_students): void {
        $this->hidden_from_students = $hidden_from_students;
    }

    public function setPriority(float $priority): void {
        $this->priority = $priority;
    }

    public function setPath(string $path): void {
        $this->path = $path;
    }

    public function setType(int $type): void {
        $this->type = $type;
    }

    public function addSection(CourseMaterialSection $section): void {
        $this->sections[] = $section;
    }

    public function getId(): int {
        return $this->id;
    }

    public function removeSection(CourseMaterialSection $section): void {
        $this->sections->removeElement($section);
    }
}
