
function renderGradeable(grading_data) {
    return new Promise(function(accept, reject) {
        Twig.twig({
            id: "Gradeable",
            href: "templates/grading/Gradeable.twig",
            async: true,
            load: function (template) {
                accept(template.render(grading_data));
            },
            error: reject
        });
        Twig.twig({
            id: "Component",
            href: "templates/grading/Component.twig",
            async: true
        });
        Twig.twig({
            id: "Mark",
            href: "templates/grading/Mark.twig",
            async: true
        });
    });
}