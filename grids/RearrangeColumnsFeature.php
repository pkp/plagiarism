<?php

/**
 * @file plugins/generic/plagiarism/grids/RearrangeColumnsFeature.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RearrangeColumnsFeature
 *
 * @brief Rearrange grid columns for the Plagisarism plugin.
 *
 */

namespace APP\plugins\generic\plagiarism\grids;

use APP\plugins\generic\plagiarism\grids\SimilarityActionGridColumn;
use PKP\controllers\grid\feature\GridFeature;
use PKP\controllers\grid\GridHandler;

class RearrangeColumnsFeature extends GridFeature
{
	/**
	 * The GridHandler instance
	 */
	public GridHandler $gridHandler;

	/**
	 * @see GridFeature::GridFeature()
	 */
	public function __construct($gridHandler)
	{
		$this->gridHandler = $gridHandler;
		parent::__construct('rearrangeColumns');
	}

	/**
	 * @see GridFeature::getGridDataElements()
	 */
	public function getGridDataElements($args)
	{
		if (!reset($this->gridHandler->_columns) instanceof SimilarityActionGridColumn) {
			return;
		}

		// The plagiarism report is the first column. Move it to the end.
		$plagiarismColumn = array_shift($this->gridHandler->_columns); /** @var SimilarityActionGridColumn $plagiarismColumn */
		$plagiarismColumn->addFlag('firstColumn', false);

		// push the plagiarism score/action column as the second column
		$afterKey = array_key_first($this->gridHandler->_columns);
		$index = array_search($afterKey, array_keys( $this->gridHandler->_columns ));
		$this->gridHandler->_columns = array_slice($this->gridHandler->_columns, 0, $index + 1)
			+ [SimilarityActionGridColumn::SIMILARITY_ACTION_GRID_COLUMN_ID => $plagiarismColumn]
			+ $this->gridHandler->_columns;

		// set the first file name column as first column
		reset($this->gridHandler->_columns)->addFlag('firstColumn', true);
	}
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\plagiarism\grids\RearrangeColumnsFeature', '\RearrangeColumnsFeature');
}
