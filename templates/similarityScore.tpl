<span class="plagiarism-similarity-score">
    <a
        target="_blank"
        href="{$viewerUrl}"
        title="{translate key="plugins.generic.plagiarism.similarity.action.launch.viewer.title"}"
    >
        <img 
            src="{$logoUrl}"
            alt="{translate key="plugins.generic.plagiarism.similarity.match.title"}"
        />
    </a>
    <span>{$score|escape}%</span>
</span>

<style>
    span.plagiarism-similarity-score {
        display: flex;
        align-items: center;
    }

    span.plagiarism-similarity-score img {
        max-width: 100px;
    }

    span.plagiarism-similarity-score span {
        padding-left: 10px;
        padding-bottom: 5px;
        font-weight: 550;
        font-size: 15px;
        color: #006798;
    }
</style>
