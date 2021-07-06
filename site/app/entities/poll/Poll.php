<?php

declare(strict_types=1);

namespace app\entities\poll;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="app\repositories\poll\PollRepository")
 * @ORM\Table(name="polls")
 */
class Poll {
  /**
   * @ORM\Id
   * @ORM\GeneratedValue
   * @ORM\Column(name="poll_id",type="integer")
   */
  private $id;

  /** @ORM\Column(name="name",type="text") */
  private $name;

  /** @ORM\Column(type="text") */
  private $question;

  /** @ORM\Column(type="text") */
  private $status;

  /**
   * @ORM\Column(type="date")
   * @var \DateTime
   */
  private $release_date;

  /** @ORM\Column(type="text",nullable=true) */
  private $image_path;

  /**
   * @ORM\Column(type="string")
   */
  private $question_type;

  /**
   * @ORM\OneToMany(targetEntity="\app\entities\poll\Option",mappedBy="poll")
   * @ORM\JoinColumn(name="poll_id", referencedColumnName="poll_id")
   * @ORM\OrderBy({"order_id" = "ASC"})
   * @var Collection<Option>
   */
  private $options;

    /**
   * @ORM\OneToMany(targetEntity="\app\entities\poll\Response",mappedBy="poll")
   * @ORM\JoinColumn(name="poll_id", referencedColumnName="poll_id")
   * @var Collection<Response>
   */
  private $responses;

  public function __construct(string $name, string $question, string $question_type, \DateTime $release_date) {
    $this->setName($name);
    $this->setQuestion($question);
    $this->setQuestionType($question_type);
    $this->setReleaseDate($release_date);

    $this->setClosed();

    $this->options = new ArrayCollection();
    $this->responses = new ArrayCollection();
  }

  public function getId(): int {
    return $this->id;
  }

  public function getName(): string {
    return $this->name;
  }

  public function setName(string $name): void {
    $this->name = $name;
  }

  public function getQuestion(): string {
    return $this->question;
  }

  public function setQuestion(string $question): void {
    $this->question = $question;
  }

  public function getStatus(): string {
    return $this->status;
  }

  public function setOpen(): void {
    $this->status = "open";
  }

  public function isOpen(): bool {
    return $this->status == "open";
  }

  public function setClosed(): void {
    $this->status = "closed";
  }

  public function isClosed(): bool {
    return $this->status == "closed";
  }

  public function setEnded(): void {
    $this->status = "ended";
  }

  public function isEnded(): bool {
    return $this->status == "ended";
  }

  public function getReleaseDate(): \DateTime {
    return $this->release_date;
  }

  public function setReleaseDate(\DateTime $release_date): void {
    $this->release_date = $release_date;
  }

  public function getImagePath(): ?string {
    return $this->image_path;
  }

  public function setImagePath(?string $image_path): void {
    $this->image_path = $image_path;
  }

  public function getQuestionType(): string {
    return $this->question_type;
  }

  public function setQuestionType(string $question_type): void {
    $this->question_type = $question_type;
  }

  /**
   * @return Collection<Option>
   */
  public function getOptions(): Collection {
    return $this->options;
  }

  public function getOptionById(int $option_id): Option {
    foreach ($this->options as $option) {
      if ($option->getId() === $option_id) {
        return $option;
      }
    }
    throw new \RuntimeException("Invalid option id");
  }

  public function addOption(Option $option): void {
    $this->options->add($option);
    $option->setPoll($this);
  }

  public function removeOption(Option $option): void {
    $this->options->removeElement($option);
    $option->detach();
  }

  public function addResponse(Response $response, int $option_id) {
    $this->responses->add($response);
    $response->setOption($this->getOptionById($option_id));
    $response->setPoll($this);
  }

  /**
   * @return Collection<Response>
   */
  public function getUserResponses(): Collection {
    return $this->responses;
  }

  /**
   * @return Collection<Response>
   */
  public function getResponses(): Collection {
    return $this->responses;
  }

  /**
   * @return Response[]
   */
  public function getResponsesByStudentId(string $user_id): array {
    $responses = [];
    foreach ($this->responses as $response) {
      if ($response->getStudentId() === $user_id) {
        $responses[] = $response;
      }
    }
    return $responses;
  }
}
