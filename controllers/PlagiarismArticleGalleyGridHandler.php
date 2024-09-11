<?php

/**
 * @file plugins/generic/plagiarism/controllers/PlagiarismArticleGalleyGridHandler.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismArticleGalleyGridHandler
 *
 * @brief 	Handle the attachment of plagiarism score/action column to galleys file grid
 * 			view for OPS
 */

namespace APP\plugins\generic\plagiarism\controllers;

use APP\controllers\grid\preprintGalleys\PreprintGalleyGridHandler;
use APP\plugins\generic\plagiarism\grids\SimilarityActionGridColumn;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;

class PlagiarismArticleGalleyGridHandler extends PreprintGalleyGridHandler
{
	/** 
	 * The Plagiarism Plugin itself
	 * 
	 * @var PlagiarismPlugin 
	 */
	protected static $_plugin;

	/**
	 * Get the plugin
	 */
	public static function getPlugin(): PlagiarismPlugin
	{
		return static::$_plugin;
	}

	/**
	 * Set the Plugin
	 */
	public static function setPlugin(PlagiarismPlugin $plugin): void
	{
		static::$_plugin = $plugin;
	}

	/**
	 * @copydoc ArticleGalleyGridHandler::initialize()
	 */
	public function initialize($request, $args = null)
	{
		parent::initialize($request, $args);
		
		$this->addColumn(new SimilarityActionGridColumn(static::$_plugin));
	}
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\plagiarism\controllers\PlagiarismArticleGalleyGridHandler', '\PlagiarismArticleGalleyGridHandler');
}
