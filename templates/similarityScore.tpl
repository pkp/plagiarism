{**
 * plugins/generic/plagiarism/templates/similarityScore.tpl
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Show the submission file's iThenticate score after plagiarism check completed
 *}

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
    <span>{$score}%</span>
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
