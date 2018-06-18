<?php

namespace app\libraries;

/**
 * Represents a button to display on the page
 * @package app\libraries
 */
class Button {
    /** @var string $title */
    protected $title;
    /** @var string|null $subtitle */
    protected $subtitle;
    /** @var string $href */
    protected $href;
    /** @var string $class */
    protected $class;
    /** @var bool $disabled */
    protected $disabled;
    /** @var float|null $progress */
    protected $progress;

    /**
     * @param array $details
     */
    public function __construct(array $details) {
        $this->title    = $details["title"] ?? "";
        $this->subtitle = $details["subtitle"] ?? null;
        $this->href     = $details["href"] ?? "";
        $this->class    = $details["class"] ?? "btn";
        $this->disabled = $details["disabled"] ?? false;
        $this->progress = $details["progress"] ?? null;
    }

    /**
     * @return string
     */
    public function getTitle(): string {
        return $this->title;
    }

    /**
     * @return null|string
     */
    public function getSubtitle() {
        return $this->subtitle;
    }

    /**
     * @return string
     */
    public function getHref(): string {
        return $this->href;
    }

    /**
     * @return string
     */
    public function getClass(): string {
        return $this->class;
    }

    /**
     * @return bool
     */
    public function isDisabled(): bool {
        return $this->disabled;
    }

    /**
     * @return float|null
     */
    public function getProgress() {
        return $this->progress;
    }

}