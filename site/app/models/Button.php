<?php

namespace app\models;
use app\libraries\Core;

/**
 * Represents a button to display on the page
 * @package app\models
 * @method string getTitle()
 * @method string|null getSubtitle()
 * @method string|null getHref()
 * @method string getClass()
 * @method bool isDisabled()
 * @method bool isTitleOnHover()
 * @method float|null getProgress()
 * @method string|null getAriaLabel()
 * @method string|null getBadge()
 *
 * @method void setTitle(string $title)
 * @method void setSubtitle(string|null $subtitle)
 * @method void setHref(string|null $href)
 * @method void setClass(string $class)
 * @method void setDisabled(bool $disabled)
 * @method void setTitleOnHover(bool $titleOnHover)
 * @method void setProgress(float|null $progress)
 * @method void setAriaLabel(string|null $ariaLabel)
 * @method void setBadge(string|null $badge)
 */
class Button extends AbstractModel {
    /** @property @var string|null $title */
    protected $title;
    /** @property @var string|null $subtitle */
    protected $subtitle;
    /** @property @var string|null $href */
    protected $href;
    /** @property @var string $class */
    protected $class;
    /** @property @var bool $disabled */
    protected $disabled;
    /** @property @var float|null $progress */
    protected $progress;
    /** @property @var bool $title_on_hover */
    protected $title_on_hover;
    /** @property @var string|null $aria_label */
    protected $aria_label;
    /** @property @var string|null $badge */
    protected $badge;

    /**
     * @param Core $core
     * @param array $details
     */
    public function __construct(Core $core, array $details) {
        parent::__construct($core);
        $this->title    = $details["title"] ?? null;
        $this->subtitle = $details["subtitle"] ?? null;
        $this->href     = $details["href"] ?? null;
        $this->class    = $details["class"] ?? "btn";
        $this->disabled = $details["disabled"] ?? false;
        $this->progress = $details["progress"] ?? null;
        if ($this->progress !== null) {
            $this->progress = floatval($this->progress);
        }
        $this->title_on_hover = $details["title_on_hover"] ?? false;
        $this->aria_label = $details["aria_label"] ?? null;
        $this->badge = $details["badge"] ?? null;
    }

}
