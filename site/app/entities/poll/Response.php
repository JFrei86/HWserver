<?php

declare(strict_types=1);

namespace app\entities\poll;

use Doctrine\ORM\Mapping as ORM;


/**
 * @ORM\Entity(repositoryClass="app\repositories\poll\ResponseRepository")
 * @ORM\Table(name="poll_responses")
 */
class Response {
  /**
   * @ORM\ID
   * @ORM\GeneratedValue
   * @ORM\Column(name="id",type="integer")
   * @var int
   */
  private $id;

  /**
   * @ORM\Column(name="student_id",type="text")
   * @var string
   */
  private $student_id;

  /**
   * @ORM\ManyToOne(targetEntity="\app\entities\poll\Poll",inversedBy="responses")
   * @ORM\JoinColumn(name="poll_id", referencedColumnName="poll_id")
   * @var Poll
   */
  private $poll;

  /**
   * @ORM\ManyToOne(targetEntity="\app\entities\poll\Option",inversedBy="user_responses")
   * @ORM\JoinColumns({
   * @ORM\JoinColumn(name="option_id", referencedColumnName="option_id"),
   * })
   * @var Option
   */
  private $option;

  public function __construct(string $student_id) {
    $this->setStudentId($student_id);
  }

  public function getId(): int {
    return $this->id;
  }

  private function setStudentId(string $student_id): void {
    $this->student_id = $student_id;
  }

  public function getStudentId(): string {
    return $this->student_id;
  }

  public function setPoll(Poll $poll): void {
    $this->poll = $poll;
  }

  public function getPoll(): Poll {
    return $this->poll;
  }

  public function setOption(Option $option): void {
    $this->option = $option;
  }

  public function getOption(): Option {
    return $this->option;
  }
}
