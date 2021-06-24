<?php

namespace app\libraries;

use app\libraries\Output;
use League\CommonMark\Environment;
use League\CommonMark\Block\Element\AbstractBlock;
use League\CommonMark\Block\Renderer\BlockRendererInterface;
use League\CommonMark\ElementRendererInterface;
use Spatie\CommonMarkHighlighter\FencedCodeRenderer;
use League\CommonMark\Block\Renderer\IndentedCodeRenderer;
use League\CommonMark\HtmlElement;

class CustomCodeRenderer implements BlockRendererInterface {
    public function __construct($baseRenderer){
        $this->baseRenderer = new $baseRenderer();
    }

    public function render(AbstractBlock $block, ElementRendererInterface $htmlRenderer, bool $inTightList = false) {
        $element = $this->baseRenderer->render($block, $htmlRenderer, $inTightList);
        $num_lines = substr_count($element->getContents(), "\n");
        $element->setContents($this->addLineNumbers($element, $num_lines));
        return $element;
    }

    private function addLineNumbers(HtmlElement $element, int $num_lines) {
        $line_numbers_content = "";
        for($num = 1; $num <= $num_lines; $num++) {
            $line_numbers_content .= strval($num) . "\n";
        }
        $line_numbers_pre = new HtmlElement('pre', ['class' => 'line-numbers'], $line_numbers_content);
        return $element . $line_numbers_pre;
    }
}


class CustomFencedCodeRenderer extends CustomCodeRenderer {
    public function __construct() {
        parent::__construct(FencedCodeRenderer::class);
    }
}

class CustomIndentedCodeRenderer extends CustomCodeRenderer {
    public function __construct() {
        parent::__construct(IndentedCodeRenderer::class);
    }
}