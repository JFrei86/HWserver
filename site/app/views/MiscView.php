<?php

namespace app\views;

use app\libraries\FileUtils;

class MiscView extends AbstractView {
    public function displayFile($file_contents) {
        return $this->core->getOutput()->renderTwigTemplate("misc/File.twig", [
            "file_contents" => $file_contents
        ]);
    }

    public function displayCode($file_type, $filename, $file_contents) {
        $code_css = [];
        $code_css[] = $this->core->getOutput()->timestampResource(FileUtils::joinPaths('codemirror', 'codemirror.css'), 'vendor');
        $code_css[] = $this->core->getOutput()->timestampResource(FileUtils::joinPaths('codemirror', 'theme', 'eclipse.css'), 'vendor');
        $code_css[] = $this->core->getOutput()->timestampResource(FileUtils::joinPaths('codemirror', 'theme', 'monokai.css'), 'vendor');

        $code_js = [];
        $code_js[] = $this->core->getOutput()->timestampResource(FileUtils::joinPaths('jquery', 'jquery.min.js'), 'vendor');
        $code_js[] = $this->core->getOutput()->timestampResource(FileUtils::joinPaths('codemirror', 'codemirror.js'), 'vendor');
        $code_js[] = $this->core->getOutput()->timestampResource(FileUtils::joinPaths('codemirror', 'mode', 'clike', 'clike.js'), 'vendor');
        $code_js[] = $this->core->getOutput()->timestampResource(FileUtils::joinPaths('codemirror', 'mode', 'python', 'python.js'), 'vendor');
        $code_js[] = $this->core->getOutput()->timestampResource(FileUtils::joinPaths('codemirror', 'mode', 'shell', 'shell.js'), 'vendor');

        return $this->core->getOutput()->renderTwigTemplate("misc/Code.twig", [
            "filename" => $filename,
            "file_contents" => $file_contents,
            "file_type" => $file_type,
            "base_url" => $this->core->getConfig()->getBaseUrl(),
            "code_css" => $code_css,
            "code_js" => $code_js
        ]);
    }

    public function tooLarge() {
        return $this->core->getOutput()->renderTwigTemplate("misc/TooLarge.twig");
    }
}
