<?php

declare(strict_types=1);

namespace app\entities\poll;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="poll_options")
 */
class Option {
  /**
   * @ORM\Id
   * @ORM\GeneratedValue
   * @ORM\Column(name="option_id",type="integer")
   * @var int
   */
  private $id;

  /**
   * @ORM\Column(name="order_id",type="integer")
   * @var int
   */
  private $order_id;

  /**
   * @ORM\Column(name="response",type="text")
   * @var string
   */
  private $response;

  /**
   * @ORM\Column(name="correct",type="boolean")
   * @var bool
   */
  private $correct;

  /**
   * @ORM\ManyToOne(targetEntity="\app\entities\poll\Poll",inversedBy="options")
   * @ORM\JoinColumn(name="poll_id", referencedColumnName="poll_id")
   * @var Poll
   */
  private $poll;

  /**
   * @ORM\OneToMany(targetEntity="\app\entities\poll\Response",mappedBy="option")
   * @ORM\JoinColumns({
   * @ORM\JoinColumn(name="option_id", referencedColumnName="option_id"),
   * })
   * @var Collection
   */
  private $user_responses;

  public function __construct(int $order_id, string $response, bool $is_correct) {
    $this->setOrderId($order_id);
    $this->setResponse($response);
    $this->setCorrect($is_correct);

    $this->user_responses = new ArrayCollection();
  }

  public function getId(): int {
    return $this->id;
  }

  public function setOrderId(int $order_id): void {
    $this->order_id = $order_id;
  }

  public function getOrderId(): int {
    return $this->order_id;
  }

  public function setResponse(string $response): void {
    $this->response = $response;
  }

  public function getResponse(): string {
    return $this->response;
  }

  public function setCorrect(bool $correct): void {
    $this->correct = $correct;
  }

  public function isCorrect(): bool {
    return $this->correct;
  }

  public function setPoll(Poll $poll): void {
    $this->poll = $poll;
  }

  public function getPoll(): Poll {
    return $this->poll;
  }

  public function detach(): void {
    $this->poll = null;
  }

  public function hasUserResponses(): bool {
    return count($this->user_responses) > 0;
  }

  /**
   * @return Collection<Response>
   */
  public function getUserResponses(): Collection {
    return $this->user_responses;
  }
}
