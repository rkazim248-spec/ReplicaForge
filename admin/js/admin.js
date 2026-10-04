/**
 * ReplicaForge admin analyzer UI.
 *
 * All remote values are inserted with textContent. The UI never injects remote
 * HTML, markup, or executable content into the WordPress admin document.
 */
( function () {
	'use strict';

	var config = window.ReplicaForgeAdmin || {};
	var strings = config.strings || {};
	var form = document.getElementById( 'replicaforge-analyze-form' );
	var input = document.getElementById( 'replicaforge-url' );
	var button = document.getElementById( 'replicaforge-analyze-button' );
	var status = document.getElementById( 'replicaforge-status' );
	var statusText = document.getElementById( 'replicaforge-status-text' );
	var statusHint = document.getElementById( 'replicaforge-status-hint' );
	var spinner = document.getElementById( 'replicaforge-spinner' );
	var errorBox = document.getElementById( 'replicaforge-error' );
	var results = document.getElementById( 'replicaforge-results' );
	var rawDetails = document.getElementById( 'replicaforge-raw-analysis' );
	var rawJson = document.getElementById( 'replicaforge-raw-json' );
	var designUnderstanding = document.getElementById( 'replicaforge-design-understanding' );
	var designStats = document.getElementById( 'replicaforge-phase2-stats' );
	var structureList = document.getElementById( 'replicaforge-structure-list' );
	var phase2Details = document.getElementById( 'replicaforge-phase2-details' );
	var representationRaw = document.getElementById( 'replicaforge-representation-raw' );
	var representationJson = document.getElementById( 'replicaforge-representation-json' );
	var designConfidence = document.getElementById( 'replicaforge-design-confidence' );
	var aiPanel = document.getElementById( 'replicaforge-ai' );
	var aiRunButton = document.getElementById( 'replicaforge-ai-run' );
	var aiProvider = document.getElementById( 'replicaforge-ai-provider' );
	var aiStatusLabel = document.getElementById( 'replicaforge-ai-status-label' );
	var aiError = document.getElementById( 'replicaforge-ai-error' );
	var aiHint = document.getElementById( 'replicaforge-ai-hint' );
	var aiPlan = document.getElementById( 'replicaforge-ai-plan' );
	var aiStrategy = document.getElementById( 'replicaforge-ai-strategy' );
	var aiSections = document.getElementById( 'replicaforge-ai-sections' );
	var aiDesign = document.getElementById( 'replicaforge-ai-design' );
	var aiResponsive = document.getElementById( 'replicaforge-ai-responsive' );
	var aiWarnings = document.getElementById( 'replicaforge-ai-warnings' );
	var aiConfidence = document.getElementById( 'replicaforge-ai-confidence' );
	var aiJson = document.getElementById( 'replicaforge-ai-json' );
	var aiJsonRaw = document.getElementById( 'replicaforge-ai-json-raw' );
	var phase4Panel = document.getElementById( 'replicaforge-phase4' );
	var phase4Status = document.getElementById( 'replicaforge-phase4-status' );
	var phase4Elementor = document.getElementById( 'replicaforge-phase4-elementor' );
	var phase4Help = document.getElementById( 'replicaforge-phase4-help' );
	var phase4RunButton = document.getElementById( 'replicaforge-phase4-run' );
	var phase4Import = document.getElementById( 'replicaforge-phase4-import' );
	var phase4Hint = document.getElementById( 'replicaforge-phase4-hint' );
	var phase4Error = document.getElementById( 'replicaforge-phase4-error' );
	var phase4Result = document.getElementById( 'replicaforge-phase4-result' );
	var phase4Draft = document.getElementById( 'replicaforge-phase4-draft' );
	var phase4Stats = document.getElementById( 'replicaforge-phase4-stats' );
	var phase4Open = document.getElementById( 'replicaforge-phase4-open' );
	var phase4ElementorEdit = document.getElementById( 'replicaforge-phase4-elementor-edit' );
	var phase4Preview = document.getElementById( 'replicaforge-phase4-preview' );
	var phase4Warnings = document.getElementById( 'replicaforge-phase4-warnings' );
	var phase4Limitations = document.getElementById( 'replicaforge-phase4-limitations' );
	var phase4ReportRaw = document.getElementById( 'replicaforge-phase4-report-raw' );
	var phase4Report = document.getElementById( 'replicaforge-phase4-report' );
	var currentRepresentation = null;
	var currentSourceUrl = '';
	var currentSpecification = null;

	var phase5Panel = document.getElementById( 'replicaforge-phase5' );
	var phase5Status = document.getElementById( 'replicaforge-phase5-status' );
	var phase5Description = document.getElementById( 'replicaforge-phase5-description' );
	var phase5Notice = document.getElementById( 'replicaforge-phase5-notice' );
	var phase5Visual = document.getElementById( 'replicaforge-phase5-visual' );
	var phase5VisualNote = document.getElementById( 'replicaforge-phase5-visual-note' );
	var phase5Ai = document.getElementById( 'replicaforge-phase5-ai' );
	var phase5Force = document.getElementById( 'replicaforge-phase5-force' );
	var phase5RunButton = document.getElementById( 'replicaforge-phase5-run' );
	var phase5Hint = document.getElementById( 'replicaforge-phase5-hint' );
	var phase5Error = document.getElementById( 'replicaforge-phase5-error' );
	var phase5Result = document.getElementById( 'replicaforge-phase5-result' );
	var phase5Summary = document.getElementById( 'replicaforge-phase5-summary' );
	var phase5Tabs = document.getElementById( 'replicaforge-phase5-tabs' );
	var phase5Panels = document.getElementById( 'replicaforge-phase5-panels' );
	var phase5ExportJson = document.getElementById( 'replicaforge-phase5-export-json' );
	var phase5ExportCsv = document.getElementById( 'replicaforge-phase5-export-csv' );
	var phase5ReportRaw = document.getElementById( 'replicaforge-phase5-report-raw' );
	var phase5Report = document.getElementById( 'replicaforge-phase5-report' );
	var currentDraftId = 0;
	var currentValidation = null;
	var validationInFlight = false;

	var phase6Panel = document.getElementById( 'replicaforge-phase6' );
	var phase6Status = document.getElementById( 'replicaforge-phase6-status' );
	var phase6Description = document.getElementById( 'replicaforge-phase6-description' );
	var phase6Notice = document.getElementById( 'replicaforge-phase6-notice' );
	var phase6Ai = document.getElementById( 'replicaforge-phase6-ai' );
	var phase6AiNote = document.getElementById( 'replicaforge-phase6-ai-note' );
	var phase6PlanButton = document.getElementById( 'replicaforge-phase6-plan' );
	var phase6Hint = document.getElementById( 'replicaforge-phase6-hint' );
	var phase6Error = document.getElementById( 'replicaforge-phase6-error' );
	var phase6Review = document.getElementById( 'replicaforge-phase6-review' );
	var phase6PlanId = document.getElementById( 'replicaforge-phase6-plan-id' );
	var phase6Counts = document.getElementById( 'replicaforge-phase6-counts' );
	var phase6Groups = document.getElementById( 'replicaforge-phase6-groups' );
	var phase6SelectAll = document.getElementById( 'replicaforge-phase6-select-all' );
	var phase6SelectNone = document.getElementById( 'replicaforge-phase6-select-none' );
	var phase6ApplySafe = document.getElementById( 'replicaforge-phase6-apply-safe' );
	var phase6ApplySelected = document.getElementById( 'replicaforge-phase6-apply-selected' );
	var phase6Structural = document.getElementById( 'replicaforge-phase6-structural' );
	var phase6ExportPlan = document.getElementById( 'replicaforge-phase6-export-plan' );
	var phase6ExportPlanCsv = document.getElementById( 'replicaforge-phase6-export-plan-csv' );
	var phase6Result = document.getElementById( 'replicaforge-phase6-result' );
	var phase6Summary = document.getElementById( 'replicaforge-phase6-summary' );
	var phase6Measurement = document.getElementById( 'replicaforge-phase6-measurement' );
	var phase6Panels = document.getElementById( 'replicaforge-phase6-panels' );
	var phase6Elementor = document.getElementById( 'replicaforge-phase6-elementor' );
	var phase6Preview = document.getElementById( 'replicaforge-phase6-preview' );
	var phase6Validate = document.getElementById( 'replicaforge-phase6-validate' );
	var phase6ExportRun = document.getElementById( 'replicaforge-phase6-export-run' );
	var currentValidationId = '';
	var currentPlan = null;
	var correctionInFlight = false;
	var currentCorrectionId = '';
	var aiRequestInFlight = false;
	var generationInFlight = false;

	if ( ! form || ! input || ! button || ! statusText || ! results ) {
		return;
	}

	function text( key, fallback ) {
		return typeof strings[ key ] === 'string' && strings[ key ] !== '' ? strings[ key ] : fallback;
	}

	function clearNode( node ) {
		if ( ! node ) {
			return;
		}
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function createElement( tag, className, value ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( typeof value !== 'undefined' && value !== null ) {
			node.textContent = String( value );
		}
		return node;
	}

	function appendTextList( parent, values, emptyLabel, className ) {
		var list = createElement( 'ul', className || 'replicaforge-data-list' );
		if ( ! Array.isArray( values ) || values.length === 0 ) {
			list.appendChild( createElement( 'li', 'replicaforge-empty', emptyLabel ) );
			parent.appendChild( list );
			return;
		}

		values.forEach( function ( value ) {
			list.appendChild( createElement( 'li', '', value ) );
		} );
		parent.appendChild( list );
	}

	function appendLabeledList( parent, label, values, className ) {
		var group = createElement( 'div', 'replicaforge-data-group' );
		group.appendChild( createElement( 'h4', 'replicaforge-data-group__title', label ) );
		appendTextList( group, values, text( 'empty', 'No values were found.' ), className );
		parent.appendChild( group );
	}

	function setHidden( node, hidden ) {
		if ( node ) {
			node.hidden = Boolean( hidden );
		}
	}

	function setLoading( loading ) {
		button.disabled = Boolean( loading );
		input.disabled = Boolean( loading );
		form.setAttribute( 'aria-busy', loading ? 'true' : 'false' );
		setHidden( spinner, ! loading );
	}

	function setStatus( message, state ) {
		statusText.textContent = message;
		status.classList.remove( 'is-loading', 'is-error', 'is-complete' );
		if ( state ) {
			status.classList.add( 'is-' + state );
		}
		if ( state !== 'loading' ) {
			status.removeAttribute( 'aria-label' );
			if ( statusHint ) {
				statusHint.textContent = '';
				setHidden( statusHint, true );
			}
		}
	}

	function setError( message ) {
		var safeMessage = typeof message === 'string' && message !== '' ? message : text( 'requestFailed', 'The analysis could not be completed. Please try again.' );
		errorBox.textContent = safeMessage;
		setHidden( errorBox, false );
		setStatus( text( 'requestFailed', 'The analysis could not be completed. Please try again.' ), 'error' );
	}

	function clearError() {
		errorBox.textContent = '';
		setHidden( errorBox, true );
	}

	function displayHost( url ) {
		if ( typeof url !== 'string' || url === '' ) {
			return '—';
		}
		try {
			return new URL( url ).hostname || url;
		} catch ( error ) {
			return url;
		}
	}

	function displayConfidence( value ) {
		return typeof value === 'number' && isFinite( value ) ? Math.round( value * 100 ) + '%' : '0%';
	}

	function addStat( parent, label, value ) {
		var card = createElement( 'div', 'replicaforge-stat' );
		card.appendChild( createElement( 'span', 'replicaforge-stat__label', label ) );
		card.appendChild( createElement( 'strong', 'replicaforge-stat__value', value ) );
		parent.appendChild( card );
	}

	function addDetailsTo( parent, title, builder ) {
		var details = createElement( 'details', 'replicaforge-details__item' );
		details.open = true;
		details.appendChild( createElement( 'summary', '', title ) );
		var body = createElement( 'div', 'replicaforge-details__body' );
		builder( body );
		details.appendChild( body );
		parent.appendChild( details );
	}

	function addDetails( title, builder ) {
		addDetailsTo( document.getElementById( 'replicaforge-details' ), title, builder );
	}

	function renderHeadings( parent, headings ) {
		var list = createElement( 'ul', 'replicaforge-data-list replicaforge-data-list--headings' );
		if ( ! Array.isArray( headings ) || headings.length === 0 ) {
			list.appendChild( createElement( 'li', 'replicaforge-empty', text( 'empty', 'No values were found.' ) ) );
		} else {
			headings.forEach( function ( heading ) {
				var item = createElement( 'li', 'replicaforge-heading-item' );
				item.appendChild( createElement( 'span', 'replicaforge-tag', ( heading && heading.tag ? heading.tag : 'heading' ).toUpperCase() ) );
				item.appendChild( createElement( 'span', '', heading && heading.text ? heading.text : '' ) );
				list.appendChild( item );
			} );
		}
		parent.appendChild( list );
	}

	function renderImages( parent, images ) {
		var list = createElement( 'ul', 'replicaforge-data-list replicaforge-data-list--images' );
		if ( ! Array.isArray( images ) || images.length === 0 ) {
			list.appendChild( createElement( 'li', 'replicaforge-empty', text( 'empty', 'No values were found.' ) ) );
		} else {
			images.forEach( function ( image ) {
				var item = createElement( 'li', 'replicaforge-image-item' );
				var label = image && typeof image.alt === 'string' && image.alt !== '' ? image.alt : 'Image';
				var dimensions = image && image.width && image.height ? ' (' + image.width + ' × ' + image.height + ')' : '';
				item.appendChild( createElement( 'strong', '', label + dimensions ) );
				item.appendChild( createElement( 'code', 'replicaforge-url-text', image && image.src ? image.src : '' ) );
				list.appendChild( item );
			} );
		}
		parent.appendChild( list );
	}

	function renderLinks( parent, links ) {
		var list = createElement( 'ul', 'replicaforge-data-list replicaforge-data-list--links' );
		if ( ! Array.isArray( links ) || links.length === 0 ) {
			list.appendChild( createElement( 'li', 'replicaforge-empty', text( 'empty', 'No values were found.' ) ) );
		} else {
			links.forEach( function ( link ) {
				var item = createElement( 'li', 'replicaforge-link-item' );
				item.appendChild( createElement( 'span', '', link && link.text ? link.text : '' ) );
				item.appendChild( createElement( 'code', 'replicaforge-url-text', link && link.url ? link.url : '' ) );
				list.appendChild( item );
			} );
		}
		parent.appendChild( list );
	}

	function renderFontList( parent, fonts ) {
		var list = createElement( 'ul', 'replicaforge-data-list' );
		if ( ! Array.isArray( fonts ) || fonts.length === 0 ) {
			list.appendChild( createElement( 'li', 'replicaforge-empty', text( 'empty', 'No values were found.' ) ) );
		} else {
			fonts.forEach( function ( font ) {
				var family = font && font.family ? font.family : '';
				var source = font && font.source ? ' · ' + font.source : '';
				list.appendChild( createElement( 'li', '', family + source ) );
			} );
		}
		parent.appendChild( list );
	}

	function countComponentTypes( components ) {
		var counts = {};
		( Array.isArray( components ) ? components : [] ).forEach( function ( component ) {
			var type = component && component.type ? String( component.type ) : 'unknown';
			counts[ type ] = ( counts[ type ] || 0 ) + 1;
		} );
		return counts;
	}

	function renderDesignUnderstanding( data ) {
		if ( ! designUnderstanding || ! designStats || ! structureList || ! phase2Details ) {
			return;
		}
		clearNode( designStats );
		clearNode( structureList );
		clearNode( phase2Details );
		if ( representationJson ) {
			representationJson.textContent = '';
		}
		setHidden( representationRaw, true );

		var representation = data && data.design_representation;
		if ( ! representation || representation.schema_version !== '2.0' ) {
			setHidden( designUnderstanding, false );
			structureList.appendChild( createElement( 'p', 'replicaforge-empty', text( 'noPhase2', 'Phase 2 design understanding was not available for this result.' ) ) );
			return;
		}

		setHidden( designUnderstanding, false );
		var sections = Array.isArray( representation.sections ) ? representation.sections : [];
		var components = Array.isArray( representation.components ) ? representation.components : [];
		var responsive = representation.responsive || {};
		var designSystem = representation.design_system || {};
		var layout = representation.layout || {};
		var tokenCount = 0;
		var colorCount = designSystem.colors && Array.isArray( designSystem.colors.tokens ) ? designSystem.colors.tokens.length : 0;
		var typographyCount = designSystem.typography && designSystem.typography.families && Array.isArray( designSystem.typography.families ) ? designSystem.typography.families.length : 0;
		var spacingCount = designSystem.spacing && Array.isArray( designSystem.spacing.scale ) ? designSystem.spacing.scale.length : 0;
		var radiusCount = designSystem.radius && Array.isArray( designSystem.radius.scale ) ? designSystem.radius.scale.length : 0;
		var shadowCount = Array.isArray( designSystem.shadows ) ? designSystem.shadows.length : 0;
		var buttonCount = Array.isArray( designSystem.buttons ) ? designSystem.buttons.length : 0;
		tokenCount = colorCount + typographyCount + spacingCount + radiusCount + shadowCount + buttonCount;

		addStat( designStats, 'Regions', layout.regions && Array.isArray( layout.regions ) ? layout.regions.length : 0 );
		addStat( designStats, 'Sections', sections.length );
		addStat( designStats, 'Components', components.length );
		addStat( designStats, 'Design Tokens', tokenCount );
		addStat( designStats, 'Breakpoints', responsive.breakpoints && Array.isArray( responsive.breakpoints ) ? responsive.breakpoints.length : 0 );
		addStat( designStats, 'Responsive Rules', responsive.rules && Array.isArray( responsive.rules ) ? responsive.rules.length : 0 );
		if ( designConfidence ) {
			designConfidence.textContent = representation.confidence && typeof representation.confidence.overall === 'number' ? 'Confidence ' + displayConfidence( representation.confidence.overall ) : '';
		}

		if ( sections.length === 0 ) {
			structureList.appendChild( createElement( 'p', 'replicaforge-empty', text( 'empty', 'No values were found.' ) ) );
		} else {
			var sectionList = createElement( 'ol', 'replicaforge-section-list replicaforge-structure__list' );
			sections.forEach( function ( section ) {
				var item = createElement( 'li', 'replicaforge-section-item' );
				item.appendChild( createElement( 'strong', '', section.heading || section.type || 'Section' ) );
				item.appendChild( createElement( 'span', 'replicaforge-tag', section.type || 'unknown' ) );
				var layoutType = section.layout && section.layout.type ? section.layout.type : 'unknown layout';
				var componentTotal = section.components && Array.isArray( section.components ) ? section.components.length : 0;
				item.appendChild( createElement( 'span', 'replicaforge-section-item__meta', layoutType + ' · ' + componentTotal + ' components · ' + displayConfidence( section.confidence ) ) );
				sectionList.appendChild( item );
			} );
			structureList.appendChild( sectionList );
		}

		var componentCounts = countComponentTypes( components );
		var componentValues = Object.keys( componentCounts ).map( function ( type ) { return type + ': ' + componentCounts[ type ]; } );
		addDetailsTo( phase2Details, text( 'componentCounts', 'Components' ), function( parent ) {
			appendTextList( parent, componentValues, text( 'empty', 'No values were found.' ), 'replicaforge-chip-list' );
		} );
		addDetailsTo( phase2Details, text( 'tokenCounts', 'Design Tokens' ), function( parent ) {
			var list = createElement( 'ul', 'replicaforge-data-list' );
			list.appendChild( createElement( 'li', '', 'Colors: ' + colorCount ) );
			list.appendChild( createElement( 'li', '', 'Font families: ' + typographyCount ) );
			list.appendChild( createElement( 'li', '', 'Spacing values: ' + spacingCount ) );
			list.appendChild( createElement( 'li', '', 'Radius values: ' + radiusCount ) );
			list.appendChild( createElement( 'li', '', 'Shadows: ' + shadowCount ) );
			list.appendChild( createElement( 'li', '', 'Button variants: ' + buttonCount ) );
			parent.appendChild( list );
		} );
		addDetailsTo( phase2Details, text( 'responsiveRules', 'Responsive Rules' ), function( parent ) {
			var list = createElement( 'ul', 'replicaforge-data-list' );
			var mobile = responsive.mobile_navigation || {};
			list.appendChild( createElement( 'li', '', 'Mobile navigation: ' + ( mobile.detected ? 'detected' : 'not detected' ) + ' (' + ( mobile.behavior || 'unknown' ) + ')' ) );
			( responsive.breakpoints && Array.isArray( responsive.breakpoints ) ? responsive.breakpoints : [] ).forEach( function( breakpoint ) {
				list.appendChild( createElement( 'li', '', ( breakpoint.value || 'query' ) + ' · ' + ( breakpoint.rules_count || 0 ) + ' rules' ) );
			} );
			( responsive.rules && Array.isArray( responsive.rules ) ? responsive.rules : [] ).slice( 0, 30 ).forEach( function( rule ) {
				list.appendChild( createElement( 'li', '', ( rule.breakpoint || 'query' ) + ' · ' + ( rule.changes || [] ).join( ', ' ) ) );
			} );
			parent.appendChild( list );
		} );

		if ( representationJson ) {
			representationJson.textContent = JSON.stringify( representation, null, 2 );
			setHidden( representationRaw, false );
		}
	}

	function setAiLoading( loading ) {
		aiRequestInFlight = Boolean( loading );
		if ( aiRunButton ) {
			aiRunButton.disabled = Boolean( loading );
		}
		if ( aiStatusLabel && loading ) {
			aiStatusLabel.textContent = text( 'aiRunning', 'Running AI design analysis...' );
		}
	}

	function setAiError( message ) {
		if ( ! aiError ) {
			return;
		}
		aiError.textContent = typeof message === 'string' && message !== '' ? message : text( 'aiUnavailable', 'AI analysis unavailable. The deterministic design analysis is still available.' );
		aiError.hidden = false;
	}

	function clearAiError() {
		if ( aiError ) {
			aiError.textContent = '';
			aiError.hidden = true;
		}
		if ( aiHint ) {
			aiHint.textContent = '';
			aiHint.hidden = true;
		}
	}

	function appendAiList( parent, values, emptyLabel, className ) {
		var list = createElement( 'ul', className || 'replicaforge-data-list' );
		if ( ! Array.isArray( values ) || values.length === 0 ) {
			list.appendChild( createElement( 'li', 'replicaforge-empty', emptyLabel ) );
		} else {
			values.forEach( function ( value ) {
				list.appendChild( createElement( 'li', '', value ) );
			} );
		}
		parent.appendChild( list );
	}

	function addAiLabeled( parent, label, value ) {
		var row = createElement( 'div', 'replicaforge-ai__row' );
		row.appendChild( createElement( 'span', 'replicaforge-ai__label', label ) );
		row.appendChild( createElement( 'span', 'replicaforge-ai__value', value === null || typeof value === 'undefined' || value === '' ? 'unknown' : value ) );
		parent.appendChild( row );
	}

	function aiValue( value ) {
		if ( value === null || typeof value === 'undefined' || value === '' ) {
			return 'unknown';
		}
		if ( typeof value === 'object' ) {
			return JSON.stringify( value );
		}
		return String( value );
	}

	function renderAiPlan( payload ) {
		if ( ! aiPlan || ! payload || ! payload.data || payload.data.schema_version !== '3.0' ) {
			setAiError( text( 'aiUnavailable', 'AI analysis unavailable. The deterministic design analysis is still available.' ) );
			return;
		}
		var spec = payload.data;
		clearAiError();
		currentSpecification = spec;
		renderPhase4Preview();
		setHidden( aiPlan, false );
		if ( aiStatusLabel ) {
			aiStatusLabel.textContent = payload.ai_used === true ? ( 'AI used · ' + ( payload.provider || 'provider' ) + ( payload.cached === true ? ' · cached' : '' ) ) : text( 'aiFallback', 'Deterministic fallback' );
		}
		if ( aiHint && typeof payload.message === 'string' && payload.message !== '' ) {
			aiHint.textContent = payload.message;
			setHidden( aiHint, false );
		}
		if ( aiConfidence ) {
			aiConfidence.textContent = text( 'aiConfidence', 'Inference confidence' ) + ': ' + displayConfidence( spec.confidence && typeof spec.confidence.overall === 'number' ? spec.confidence.overall : 0 );
		}
		if ( aiStrategy ) {
			clearNode( aiStrategy );
			var strategy = spec.page_strategy || {};
			[ 'layout_type', 'container_strategy', 'section_spacing_strategy', 'global_typography', 'responsive_strategy' ].forEach( function ( key ) {
				addAiLabeled( aiStrategy, key.replace( /_/g, ' ' ), aiValue( strategy[ key ] ) );
			} );
		}
		if ( aiSections ) {
			clearNode( aiSections );
			var sections = Array.isArray( spec.sections ) ? spec.sections : [];
			var sectionList = createElement( 'ol', 'replicaforge-ai__list' );
			if ( sections.length === 0 ) {
				sectionList.appendChild( createElement( 'li', 'replicaforge-empty', text( 'empty', 'No values were found.' ) ) );
			} else {
				sections.forEach( function ( section, index ) {
					var item = createElement( 'li', 'replicaforge-ai__item' );
					item.appendChild( createElement( 'strong', '', ( index + 1 ) + '. ' + ( section && section.type ? section.type : 'unknown' ) ) );
					item.appendChild( createElement( 'span', 'replicaforge-ai__meta', ( section && section.source_id ? section.source_id : '' ) + ' · ' + ( section && section.layout && section.layout.type ? section.layout.type : 'unknown layout' ) + ' · ' + displayConfidence( section && typeof section.confidence === 'number' ? section.confidence : 0 ) ) );
					sectionList.appendChild( item );
				} );
			}
			aiSections.appendChild( sectionList );
		}
		if ( aiDesign ) {
			clearNode( aiDesign );
			var design = spec.design_system || {};
			var designList = createElement( 'ul', 'replicaforge-data-list' );
			var colors = Array.isArray( design.colors ) ? design.colors : [];
			designList.appendChild( createElement( 'li', '', 'Colors: ' + ( colors.length ? colors.map( function ( item ) { return item && item.role ? item.role + ' = ' + aiValue( item.value ) : aiValue( item ); } ).join( ', ' ) : 'not detected' ) ) );
			var typography = Array.isArray( design.typography ) ? design.typography : [];
			designList.appendChild( createElement( 'li', '', 'Typography: ' + ( typography.length ? typography.map( function ( item ) { return item && item.role ? item.role : 'token'; } ).join( ', ' ) : 'not detected' ) ) );
			designList.appendChild( createElement( 'li', '', 'Spacing: ' + ( design.spacing && design.spacing.scale && design.spacing.scale.length ? design.spacing.scale.length + ' values' : 'not detected' ) ) );
			designList.appendChild( createElement( 'li', '', 'Radius: ' + ( design.radius && design.radius.scale && design.radius.scale.length ? design.radius.scale.length + ' values' : 'not detected' ) ) );
			designList.appendChild( createElement( 'li', '', 'Shadows: ' + ( Array.isArray( design.shadows ) ? design.shadows.length : 0 ) ) );
			aiDesign.appendChild( designList );
		}
		if ( aiResponsive ) {
			clearNode( aiResponsive );
			var responsive = spec.responsive_strategy || {};
			var responsiveList = createElement( 'ul', 'replicaforge-data-list' );
			[ 'desktop', 'tablet', 'mobile' ].forEach( function ( viewport ) {
				var viewportData = responsive[ viewport ] || {};
				responsiveList.appendChild( createElement( 'li', '', viewport.charAt( 0 ).toUpperCase() + viewport.slice( 1 ) + ': ' + aiValue( viewportData.layout ) ) );
			} );
			responsiveList.appendChild( createElement( 'li', '', 'Evidence records: ' + ( responsive.evidence && responsive.evidence.length ? responsive.evidence.length : 0 ) ) );
			aiResponsive.appendChild( responsiveList );
		}
		if ( aiWarnings ) {
			clearNode( aiWarnings );
			var warnings = Array.isArray( spec.warnings ) ? spec.warnings : [];
			appendAiList( aiWarnings, warnings, text( 'empty', 'No values were found.' ), 'replicaforge-ai__list' );
		}
		if ( aiJson ) {
			aiJson.textContent = JSON.stringify( spec, null, 2 );
			setHidden( aiJsonRaw, false );
		}
	}

	function requestAiPlan() {
		if ( aiRequestInFlight || ! currentRepresentation || ! config.aiEndpoint || ! config.nonce ) {
			return;
		}
		clearAiError();
		setHidden( aiPlan, true );
		setAiLoading( true );
		if ( aiHint ) {
			aiHint.textContent = text( 'aiStages', 'Validating layout, component relationships, responsive evidence, and the final reconstruction result.' );
			setHidden( aiHint, false );
		}
		fetch( config.aiEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-WP-Nonce': config.nonce || '' },
			body: JSON.stringify( { design_representation: currentRepresentation, source_url: currentSourceUrl } )
		} ).then( function ( response ) {
			return response.json().catch( function () { return null; } ).then( function ( payload ) {
				if ( ! response.ok || ! payload || payload.success !== true ) {
					throw new Error( extractError( payload ) );
				}
				return payload;
			} );
		} ).then( renderAiPlan ).catch( function ( error ) {
			if ( aiStatusLabel ) {
				aiStatusLabel.textContent = '';
			}
			setAiError( error && error.message ? error.message : text( 'aiUnavailable', 'AI analysis unavailable. The deterministic design analysis is still available.' ) );
		} ).then( function () {
			setAiLoading( false );
		} );
	}

	function renderAiAvailability( data ) {
		if ( ! aiPanel ) {
			return;
		}
		var representation = data && data.design_representation;
		if ( ! representation || representation.schema_version !== '2.0' ) {
			setHidden( aiPanel, true );
			setHidden( aiPlan, true );
			return;
		}
		setHidden( aiPanel, false );
		setHidden( aiPlan, true );
		if ( aiProvider ) {
			var provider = config.ai && config.ai.provider ? config.ai.provider : 'none';
			aiProvider.textContent = config.ai && config.ai.enabled ? ( 'Provider: ' + provider + ( config.ai.model ? ' · ' + config.ai.model : '' ) ) : text( 'aiDisabled', 'AI is not configured. The deterministic reconstruction plan will be shown.' );
		}
		if ( aiStatusLabel ) {
			aiStatusLabel.textContent = '';
		}
	}

	function renderResults( data ) {
		var website = data.website || {};
		var meta = data.meta || {};
		var content = data.content || {};
		var design = data.design || {};
		var responsive = data.responsive || {};
		var analysis = data.analysis || {};
		var summary = document.getElementById( 'replicaforge-summary' );
		var contentStats = document.getElementById( 'replicaforge-content-stats' );
		var designStats = document.getElementById( 'replicaforge-design-stats' );
		var sectionsList = document.getElementById( 'replicaforge-sections-list' );
		var details = document.getElementById( 'replicaforge-details' );

		clearNode( summary );
		clearNode( contentStats );
		clearNode( designStats );
		clearNode( sectionsList );
		clearNode( details );

		addStat( summary, 'Website', displayHost( website.final_url || website.url ) );
		addStat( summary, 'Website Type', ( website.type || 'Unknown' ) + ' · ' + displayConfidence( website.type_confidence ) );
		addStat( summary, 'Title', website.title || '—' );
		addStat( summary, 'HTTP Status', website.status || '—' );

		addStat( contentStats, 'Headings', Array.isArray( content.headings ) ? content.headings.length : 0 );
		addStat( contentStats, 'Paragraphs', Array.isArray( content.paragraphs ) ? content.paragraphs.length : 0 );
		addStat( contentStats, 'Images', Array.isArray( content.images ) ? content.images.length : 0 );
		addStat( contentStats, 'Links', Array.isArray( content.links ) ? content.links.length : 0 );

		addStat( designStats, 'Colors', Array.isArray( design.colors ) ? design.colors.length : 0 );
		addStat( designStats, 'Fonts', Array.isArray( design.fonts ) ? design.fonts.length : 0 );
		addStat( designStats, 'Breakpoints', Array.isArray( responsive.breakpoints ) ? responsive.breakpoints.length : 0 );
		addStat( designStats, 'Sections', Array.isArray( data.sections ) ? data.sections.length : 0 );

		if ( ! Array.isArray( data.sections ) || data.sections.length === 0 ) {
			sectionsList.appendChild( createElement( 'p', 'replicaforge-empty', text( 'empty', 'No values were found.' ) ) );
		} else {
			var sectionList = createElement( 'ol', 'replicaforge-section-list' );
			data.sections.forEach( function ( section ) {
				var item = createElement( 'li', 'replicaforge-section-item' );
				var heading = createElement( 'strong', '', section && section.heading ? section.heading : ( section && section.type ? section.type : 'Section' ) );
				item.appendChild( heading );
				if ( section && section.type ) {
					item.appendChild( createElement( 'span', 'replicaforge-tag', section.type ) );
				}
				if ( section && Array.isArray( section.elements ) && section.elements.length ) {
					item.appendChild( createElement( 'span', 'replicaforge-section-item__meta', section.elements.join( ' · ' ) ) );
				}
				sectionList.appendChild( item );
			} );
			sectionsList.appendChild( sectionList );
		}

		addDetails( text( 'metadata', 'Metadata' ), function ( parent ) {
			var list = createElement( 'ul', 'replicaforge-data-list' );
			list.appendChild( createElement( 'li', '', 'Language: ' + ( meta.language || '—' ) ) );
			list.appendChild( createElement( 'li', '', 'Description: ' + ( meta.description || '—' ) ) );
			list.appendChild( createElement( 'li', '', 'Viewport: ' + ( meta.viewport || '—' ) ) );
			parent.appendChild( list );
		} );
		addDetails( text( 'headings', 'Headings' ), function ( parent ) {
			renderHeadings( parent, content.headings );
		} );
		addDetails( text( 'paragraphs', 'Paragraphs' ), function ( parent ) {
			var paragraphs = Array.isArray( content.paragraphs ) ? content.paragraphs : [];
			appendTextList( parent, paragraphs.map( function ( paragraph ) { return paragraph && paragraph.text ? paragraph.text : ''; } ), text( 'empty', 'No values were found.' ), 'replicaforge-data-list replicaforge-data-list--paragraphs' );
		} );
		addDetails( text( 'images', 'Images' ), function ( parent ) {
			renderImages( parent, content.images );
		} );
		addDetails( text( 'links', 'Links' ), function ( parent ) {
			renderLinks( parent, content.links );
		} );
		addDetails( text( 'designSignals', 'Design signals' ), function ( parent ) {
			appendLabeledList( parent, text( 'colors', 'Colors' ), design.colors || [], 'replicaforge-chip-list' );
			var fontGroup = createElement( 'div', 'replicaforge-data-group' );
			fontGroup.appendChild( createElement( 'h4', 'replicaforge-data-group__title', text( 'fonts', 'Fonts' ) ) );
			renderFontList( fontGroup, design.fonts || [] );
			parent.appendChild( fontGroup );
			appendLabeledList( parent, text( 'spacing', 'Spacing' ), design.spacing || [] );
			appendLabeledList( parent, text( 'borderRadius', 'Border radius' ), design.border_radius || [] );
			appendLabeledList( parent, text( 'containerWidths', 'Container widths' ), design.container_widths || [] );
		} );
		addDetails( text( 'responsive', 'Responsive signals' ), function ( parent ) {
			var list = createElement( 'ul', 'replicaforge-data-list' );
			list.appendChild( createElement( 'li', '', 'Viewport meta: ' + ( responsive.viewport_meta ? 'yes' : 'no' ) ) );
			list.appendChild( createElement( 'li', '', 'Media queries: ' + ( responsive.media_queries_detected ? 'detected' : 'not detected' ) ) );
			var breakpoints = Array.isArray( responsive.breakpoints ) ? responsive.breakpoints : [];
			list.appendChild( createElement( 'li', '', 'Breakpoints: ' + ( breakpoints.length ? breakpoints.join( ', ' ) : '—' ) ) );
			parent.appendChild( list );
		} );

		renderDesignUnderstanding( data );
		document.getElementById( 'replicaforge-duration' ).textContent = analysis.duration_ms ? analysis.duration_ms + ' ms' : '';
		rawJson.textContent = JSON.stringify( data, null, 2 );
		setHidden( rawDetails, false );
		currentRepresentation = data && data.design_representation && data.design_representation.schema_version === '2.0' ? data.design_representation : null;
		currentSourceUrl = data && data.website && typeof data.website.final_url === 'string' ? data.website.final_url : ( data && data.website && typeof data.website.url === 'string' ? data.website.url : '' );
		renderAiAvailability( data );
		renderPhase4Availability( data );
		setHidden( results, false );
		results.setAttribute( 'tabindex', '-1' );
		results.focus();
	}

	function clearPhase4State() {
		currentSpecification = null;
		generationInFlight = false;
		setHidden( phase4Result, true );
		setHidden( phase4Hint, true );
		setHidden( phase4ReportRaw, true );
		if ( phase4Error ) {
			phase4Error.textContent = '';
			phase4Error.hidden = true;
		}
		if ( phase4Report ) {
			phase4Report.textContent = '';
		}
		if ( phase4Status ) {
			phase4Status.textContent = '';
		}
		if ( phase4RunButton ) {
			phase4RunButton.disabled = false;
		}
	}

	function setPhase4Error( message ) {
		if ( ! phase4Error ) {
			return;
		}
		phase4Error.textContent = typeof message === 'string' && message !== '' ? message : text( 'genFailed', 'The Elementor draft could not be generated.' );
		phase4Error.hidden = false;
	}

	function countByType( list, key ) {
		var counts = {};
		( Array.isArray( list ) ? list : [] ).forEach( function ( item ) {
			var value = item && item[ key ] ? String( item[ key ] ) : 'unknown';
			counts[ value ] = ( counts[ value ] || 0 ) + 1;
		} );
		return Object.keys( counts ).map( function ( type ) {
			return type + ': ' + counts[ type ];
		} );
	}

	function responsiveSummary( spec ) {
		var responsive = spec && spec.responsive_strategy ? spec.responsive_strategy : {};
		var list = [];
		[ 'desktop', 'tablet', 'mobile' ].forEach( function ( viewport ) {
			var record = responsive[ viewport ] || {};
			list.push( viewport.charAt( 0 ).toUpperCase() + viewport.slice( 1 ) + ': ' + aiValue( record.layout ) );
		} );
		var evidence = responsive.evidence && responsive.evidence.length ? responsive.evidence.length : 0;
		list.push( 'Evidence records: ' + evidence );
		return list;
	}

	function renderPhase4Availability( data ) {
		if ( ! phase4Panel ) {
			return;
		}

		var representation = data && data.design_representation;
		if ( ! representation || representation.schema_version !== '2.0' ) {
			setHidden( phase4Panel, true );
			clearPhase4State();
			return;
		}

		var elementor = config.elementor || {};
		setHidden( phase4Panel, false );
		setHidden( phase4Result, true );

		if ( phase4Elementor ) {
			phase4Elementor.textContent = elementor.available === true
				? text( 'genAvailable', 'Elementor is active and ready.' ) + ( elementor.version ? ' · ' + elementor.version : '' )
				: text( 'genUnavailable', 'Elementor is required to generate editable replicas. Please install and activate Elementor, then try again.' );
		}

		if ( phase4Help ) {
			phase4Help.textContent = text( 'genIntro', 'Turn the validated reconstruction specification into an editable Elementor page. ReplicaForge creates a new draft.' );
		}

		if ( phase4RunButton ) {
			phase4RunButton.disabled = elementor.available !== true;
		}

		renderPhase4Preview();
		renderPhase5Availability();
	}

	function renderPhase4Preview() {
		if ( ! phase4Stats || ! phase4Panel || phase4Panel.hidden ) {
			return;
		}

		var spec = currentSpecification;
		var representation = currentRepresentation;
		clearNode( phase4Stats );

		if ( ! spec ) {
			addStat( phase4Stats, text( 'aiTitle', 'AI Design Analysis' ), text( 'aiNotAvailable', 'No AI plan is available yet.' ) );
			addStat( phase4Stats, 'Source website', displayHost( currentSourceUrl ) );
			addStat( phase4Stats, 'Specification', '3.0 deterministic plan (rebuilt on the server)' );
			addStat( phase4Stats, 'Published', 'Never' );
			if ( phase4Status ) {
				phase4Status.textContent = '';
			}
			return;
		}

		var sections = Array.isArray( spec.sections ) ? spec.sections : [];
		var components = Array.isArray( spec.components ) ? spec.components : [];
		var assets = Array.isArray( spec.assets ) ? spec.assets : [];

		addStat( phase4Stats, 'Source website', displayHost( spec.provenance && spec.provenance.source_host ? spec.provenance.source_host : currentSourceUrl ) );
		addStat( phase4Stats, 'Sections', sections.length );
		addStat( phase4Stats, 'Components', components.length );
		addStat( phase4Stats, 'Image assets', assets.length );
		addStat( phase4Stats, 'AI used', spec.provenance && spec.provenance.ai_used === true ? 'Yes · ' + aiValue( spec.provenance.provider ) : 'No (deterministic plan)' );
		addStat( phase4Stats, 'Inference confidence', displayConfidence( spec.confidence && typeof spec.confidence.overall === 'number' ? spec.confidence.overall : 0 ) );
		addStat( phase4Stats, 'Desktop', aiValue( spec.responsive_strategy && spec.responsive_strategy.desktop ? spec.responsive_strategy.desktop.layout : 'unknown' ) );
		addStat( phase4Stats, 'Tablet', aiValue( spec.responsive_strategy && spec.responsive_strategy.tablet ? spec.responsive_strategy.tablet.layout : 'unknown' ) );
		addStat( phase4Stats, 'Mobile', aiValue( spec.responsive_strategy && spec.responsive_strategy.mobile ? spec.responsive_strategy.mobile.layout : 'unknown' ) );
		addStat( phase4Stats, 'Component types', countByType( components, 'reconstruction_type' ).join( ', ' ) || 'none' );
		addStat( phase4Stats, 'Warnings', Array.isArray( spec.warnings ) ? spec.warnings.length : 0 );
		addStat( phase4Stats, 'Spec source', representation && representation.page ? aiValue( representation.page.final_url || representation.page.url ) : '—' );

		if ( phase4Status ) {
			phase4Status.textContent = text( 'genSummary', 'Generation summary' ) + ' · ' + sections.length + ' / ' + components.length + ' / ' + assets.length;
		}

		if ( phase4Report ) {
			phase4Report.textContent = JSON.stringify( spec, null, 2 );
			setHidden( phase4ReportRaw, false );
		}
	}

	function renderGenerationResult( payload ) {
		if ( ! phase4Result || ! payload ) {
			return;
		}


		setHidden( phase4Result, false );
		currentDraftId = typeof payload.draft_id === 'number' ? payload.draft_id : 0;
		renderPhase5Availability();
		if ( phase4Draft ) {
			phase4Draft.textContent = payload.draft_id ? text( 'genDraft', 'Draft' ) + ' #' + payload.draft_id + ' · ' + text( 'genNotPublished', 'The generated page is a draft.' ) : '';
		}

		if ( phase4Stats ) {
			clearNode( phase4Stats );
			addStat( phase4Stats, 'Sections', payload.generated_sections || 0 );
			addStat( phase4Stats, 'Components', payload.generated_components || 0 );
			addStat( phase4Stats, 'Elements', payload.generated_elements || 0 );
			addStat( phase4Stats, 'Assets imported', payload.generated_assets || 0 );
			var report = payload.report || {};
			addStat( phase4Stats, 'Components skipped', report.components && typeof report.components.skipped === 'number' ? report.components.skipped : 0 );
			addStat( phase4Stats, 'Desktop', aiValue( report.responsive && report.responsive.desktop ) );
			addStat( phase4Stats, 'Tablet', aiValue( report.responsive && report.responsive.tablet ) );
			addStat( phase4Stats, 'Mobile', aiValue( report.responsive && report.responsive.mobile ) );
			addStat( phase4Stats, 'Duration', typeof payload.duration_ms === 'number' ? payload.duration_ms + ' ms' : '—' );
		}

		assignLink( phase4Open, payload.edit_url );
		assignLink( phase4ElementorEdit, payload.elementor_edit_url );
		assignLink( phase4Preview, payload.preview_url );

		if ( phase4Warnings ) {
			clearNode( phase4Warnings );
			appendAiList( phase4Warnings, Array.isArray( payload.warnings ) ? payload.warnings : [], text( 'empty', 'No values were found.' ), 'replicaforge-ai__list' );
		}

		if ( phase4Limitations ) {
			clearNode( phase4Limitations );
			appendAiList( phase4Limitations, report.limitations || [], text( 'empty', 'No values were found.' ), 'replicaforge-ai__list' );
		}

		if ( phase4Report ) {
			phase4Report.textContent = JSON.stringify( report, null, 2 );
			setHidden( phase4ReportRaw, false );
		}

		if ( phase4Status ) {
			phase4Status.textContent = text( 'genSuccess', 'Replica generated successfully.' );
		}
		if ( phase4Hint ) {
			phase4Hint.textContent = '';
			setHidden( phase4Hint, true );
		}
		if ( phase4Error ) {
			phase4Error.textContent = '';
			phase4Error.hidden = true;
		}
	}

	function assignLink( node, href ) {
		if ( ! node ) {
			return;
		}
		if ( typeof href === 'string' && href !== '' ) {
			node.setAttribute( 'href', href );
			node.hidden = false;
		} else {
			node.hidden = true;
		}
	}

	function requestGeneration() {
		if ( generationInFlight || ! currentRepresentation || ! config.generateEndpoint || ! config.nonce ) {
			return;
		}
		if ( ! config.elementor || config.elementor.available !== true ) {
			setPhase4Error( text( 'genUnavailable', 'Elementor is required to generate editable replicas. Please install and activate Elementor, then try again.' ) );
			return;
		}

		generationInFlight = true;
		setHidden( phase4Result, true );
		setHidden( phase4ReportRaw, true );
		if ( phase4Error ) {
			phase4Error.textContent = '';
			phase4Error.hidden = true;
		}
		if ( phase4RunButton ) {
			phase4RunButton.disabled = true;
		}
		if ( phase4Status ) {
			phase4Status.textContent = text( 'genRunning', 'Generating the Elementor draft...' );
		}
		if ( phase4Hint ) {
			phase4Hint.textContent = text( 'genStages', 'Validating the specification, building containers and widgets, applying responsive settings, creating the draft, and re-reading the saved document.' );
			setHidden( phase4Hint, false );
		}

		var body = {
			design_representation: currentRepresentation,
			source_url: currentSourceUrl,
			import_assets: phase4Import ? phase4Import.checked === true : false,
			mode: 'generate'
		};
		if ( currentSpecification ) {
			body.reconstruction_specification = currentSpecification;
		}

		fetch( config.generateEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-WP-Nonce': config.nonce || '' },
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().catch( function () { return null; } ).then( function ( payload ) {
				if ( ! response.ok || ! payload || payload.success !== true || ! payload.data ) {
					throw new Error( extractError( payload ) );
				}
				return payload.data;
			} );
		} ).then( renderGenerationResult ).catch( function ( error ) {
			if ( phase4Status ) {
				phase4Status.textContent = '';
			}
			setPhase4Error( error && error.message ? error.message : text( 'genFailed', 'The Elementor draft could not be generated.' ) );
		} ).then( function () {
			generationInFlight = false;
			if ( phase4RunButton ) {
				phase4RunButton.disabled = ! ( config.elementor && config.elementor.available === true );
			}
		} );
	}


	var VALIDATION_TABS = [
		'overview', 'desktop', 'tablet', 'mobile', 'structure', 'typography',
		'colors', 'spacing', 'assets', 'content', 'responsive', 'warnings'
	];

	var TAB_LABELS = {
		overview: 'Overview',
		desktop: 'Desktop',
		tablet: 'Tablet',
		mobile: 'Mobile',
		structure: 'Structure',
		typography: 'Typography',
		colors: 'Colors',
		spacing: 'Spacing',
		assets: 'Assets',
		content: 'Content',
		responsive: 'Responsive',
		warnings: 'Warnings'
	};

	var CATEGORY_TABS = {
		structure: 'structure',
		section_order: 'structure',
		component: 'structure',
		layout: 'overview',
		spacing: 'spacing',
		typography: 'typography',
		color: 'colors',
		background: 'colors',
		border: 'colors',
		shadow: 'colors',
		image: 'assets',
		asset: 'assets',
		content: 'content',
		link: 'content',
		responsive: 'responsive',
		navigation: 'responsive',
		visibility: 'responsive',
		interaction: 'responsive'
	};

	function clearPhase5State() {
		currentValidation = null;
		validationInFlight = false;
		setHidden( phase5Result, true );
		setHidden( phase5Hint, true );
		setHidden( phase5ReportRaw, true );
		if ( phase5Error ) {
			phase5Error.textContent = '';
			phase5Error.hidden = true;
		}
		if ( phase5Notice ) {
			phase5Notice.textContent = '';
			setHidden( phase5Notice, true );
		}
		if ( phase5Report ) {
			phase5Report.textContent = '';
		}
		if ( phase5Status ) {
			phase5Status.textContent = '';
		}
		if ( phase5RunButton ) {
			phase5RunButton.disabled = true;
		}
		if ( phase5Summary ) {
			clearNode( phase5Summary );
		}
		if ( phase5Tabs ) {
			clearNode( phase5Tabs );
		}
		if ( phase5Panels ) {
			clearNode( phase5Panels );
		}
	}

	function setPhase5Error( message ) {
		if ( ! phase5Error ) {
			return;
		}
		phase5Error.textContent = typeof message === 'string' && message !== '' ? message : text( 'valFailed', 'The validation could not be completed.' );
		phase5Error.hidden = false;
	}

	function renderPhase5Availability() {
		if ( ! phase5Panel ) {
			return;
		}

		var ready = !! ( currentRepresentation && currentDraftId > 0 );
		setHidden( phase5Panel, false );

		if ( phase5Description ) {
			phase5Description.textContent = text( 'valIntro', 'Measure how closely the generated draft matches the analyzed source.' );
		}

		var capabilities = config.validation && config.validation.capabilities ? config.validation.capabilities : {};
		if ( phase5VisualNote ) {
			phase5VisualNote.textContent = text( 'valVisualNote', 'Rendered comparison needs a configured render provider and a server image library.' ) +
				' \u00b7 ' + ( capabilities.provider_configured === true ? 'Provider configured' : 'Provider not configured' ) +
				' \u00b7 ' + ( capabilities.image_library ? 'Library: ' + capabilities.image_library : 'Library: none' );
		}

		if ( ! ready ) {
			if ( phase5Notice ) {
				phase5Notice.textContent = text( 'valNoDraft', 'Generate an Elementor draft first.' );
				setHidden( phase5Notice, false );
			}
			if ( phase5RunButton ) {
				phase5RunButton.disabled = true;
			}
			if ( phase5Status ) {
				phase5Status.textContent = '';
			}
			return;
		}

		setHidden( phase5Notice, true );
		if ( phase5RunButton ) {
			phase5RunButton.disabled = validationInFlight === true;
		}
		if ( phase5Status ) {
			phase5Status.textContent = '';
		}
	}

	function requestValidation() {
		if ( validationInFlight || ! currentRepresentation || currentDraftId < 1 || ! config.validateEndpoint || ! config.nonce ) {
			return;
		}

		validationInFlight = true;
		setHidden( phase5Result, true );
		setHidden( phase5ReportRaw, true );
		if ( phase5Error ) {
			phase5Error.textContent = '';
			phase5Error.hidden = true;
		}
		if ( phase5RunButton ) {
			phase5RunButton.disabled = true;
		}
		if ( phase5Status ) {
			phase5Status.textContent = text( 'valRunning', 'Validating the draft...' );
		}
		if ( phase5Hint ) {
			phase5Hint.textContent = text( 'valStages', 'Normalizing the source representation, reading the Elementor document, and comparing.' );
			setHidden( phase5Hint, false );
		}

		var body = {
			design_representation: currentRepresentation,
			draft_id: currentDraftId,
			visual: phase5Visual ? phase5Visual.checked === true : false,
			ai: phase5Ai ? phase5Ai.checked === true : false,
			force: phase5Force ? phase5Force.checked === true : false
		};

		fetch( config.validateEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-WP-Nonce': config.nonce || '' },
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().catch( function () { return null; } ).then( function ( payload ) {
				if ( ! response.ok || ! payload || payload.success !== true || ! payload.data ) {
					throw new Error( extractError( payload ) );
				}
				return payload.data;
			} );
		} ).then( renderValidationReport ).catch( function ( error ) {
			if ( phase5Status ) {
				phase5Status.textContent = '';
			}
			setPhase5Error( error && error.message ? error.message : text( 'valFailed', 'The validation could not be completed.' ) );
		} ).then( function () {
			validationInFlight = false;
			renderPhase5Availability();
		} );
	}

	function renderValidationReport( report ) {
		if ( ! phase5Result || ! report ) {
			return;
		}

		currentValidation = report;
		setHidden( phase5Result, false );
		if ( phase5Hint ) {
			phase5Hint.textContent = '';
			setHidden( phase5Hint, true );
		}

		var metrics = report.metrics && typeof report.metrics === 'object' ? report.metrics : {};
		var groups = metrics.groups && typeof metrics.groups === 'object' ? metrics.groups : {};
		var overall = metrics.overall && typeof metrics.overall === 'object' ? metrics.overall : {};
		var counts = report.counts && typeof report.counts === 'object' ? report.counts : {};
		var summary = report.summary && typeof report.summary === 'object' ? report.summary : {};

		if ( phase5Summary ) {
			clearNode( phase5Summary );
			addStat( phase5Summary, text( 'valGroups', 'Metrics' ), formatScore( overall.value ) );
			Object.keys( groups ).forEach( function ( group ) {
				if ( group === 'detail' ) {
					return;
				}
				addStat( phase5Summary, groupLabel( group ), formatScore( groups[ group ] ? groups[ group ].value : null ) );
			} );
			addStat( phase5Summary, 'Differences', typeof counts.differences === 'number' ? counts.differences : 0 );
			addStat( phase5Summary, 'Not comparable', typeof metrics.not_comparable_total === 'number' ? metrics.not_comparable_total : 0 );
			addStat( phase5Summary, 'Levels', Object.keys( report.levels && typeof report.levels === 'object' ? report.levels : {} ).length );
			addStat( phase5Summary, 'Result', report.cache === 'hit' ? text( 'valCached', 'Reused from cache' ) : text( 'valFresh', 'Newly computed' ) );
		}

		if ( phase5Status ) {
			phase5Status.textContent = report.validation_id ? report.validation_id : '';
		}
		currentValidationId = report.validation_id ? report.validation_id : '';
		renderPhase6Availability();

		renderValidationTabs( report );

		if ( phase5Report ) {
			phase5Report.textContent = JSON.stringify( report, null, 2 );
			setHidden( phase5ReportRaw, false );
		}
	}

	function groupLabel( group ) {
		var labels = {
			structure: 'Structure',
			layout: 'Layout',
			spacing: 'Spacing',
			typography: 'Typography',
			colors: 'Colors',
			assets: 'Assets',
			content: 'Content',
			responsive: 'Responsive',
			detail: 'Detail'
		};
		return labels[ group ] || group;
	}

	function formatScore( value ) {
		if ( typeof value !== 'number' || ! isFinite( value ) ) {
			return text( 'valNotComparable', 'Not comparable' );
		}
		return value + '%';
	}

	function collectDifferences( report ) {
		var groups = report.viewports && typeof report.viewports === 'object' ? report.viewports : {};
		var list = [];
		Object.keys( groups ).forEach( function ( viewport ) {
			( Array.isArray( groups[ viewport ].differences ) ? groups[ viewport ].differences : [] ).forEach( function ( difference ) {
				if ( list.indexOf( difference ) === -1 ) {
					list.push( difference );
				}
			} );
		} );
		return list;
	}

	function renderValidationTabs( report ) {
		if ( ! phase5Tabs || ! phase5Panels ) {
			return;
		}
		clearNode( phase5Tabs );
		clearNode( phase5Panels );

		var all = collectDifferences( report );

		VALIDATION_TABS.forEach( function ( tab, index ) {
			var selected = index === 0;
			var tabId = 'replicaforge-phase5-tab-' + tab;
			var panelId = 'replicaforge-phase5-panel-' + tab;

			var tabButton = createElement( 'button', 'replicaforge-phase5__tab' + ( selected ? ' is-active' : '' ), TAB_LABELS[ tab ] || tab );
			tabButton.setAttribute( 'type', 'button' );
			tabButton.setAttribute( 'role', 'tab' );
			tabButton.setAttribute( 'id', tabId );
			tabButton.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			tabButton.setAttribute( 'aria-controls', panelId );

			var panel = createElement( 'div', 'replicaforge-phase5__panel' + ( selected ? ' is-active' : '' ) );
			panel.setAttribute( 'role', 'tabpanel' );
			panel.setAttribute( 'id', panelId );
			panel.setAttribute( 'aria-labelledby', tabId );
			panel.hidden = ! selected;

			buildValidationPanel( panel, tab, report, all );

			tabButton.addEventListener( 'click', function () {
				var buttons = phase5Tabs.querySelectorAll( '.replicaforge-phase5__tab' );
				var panels = phase5Panels.querySelectorAll( '.replicaforge-phase5__panel' );
				var i;
				for ( i = 0; i < buttons.length; i++ ) {
					buttons[ i ].classList.remove( 'is-active' );
					buttons[ i ].setAttribute( 'aria-selected', 'false' );
				}
				for ( i = 0; i < panels.length; i++ ) {
					panels[ i ].classList.remove( 'is-active' );
					panels[ i ].hidden = true;
				}
				tabButton.classList.add( 'is-active' );
				tabButton.setAttribute( 'aria-selected', 'true' );
				panel.classList.add( 'is-active' );
				panel.hidden = false;
			} );

			phase5Tabs.appendChild( tabButton );
			phase5Panels.appendChild( panel );
		} );
	}

	function buildValidationPanel( panel, tab, report, all ) {
		if ( tab === 'overview' ) {
			buildOverviewPanel( panel, report );
			return;
		}
		if ( tab === 'warnings' ) {
			buildWarningsPanel( panel, report );
			return;
		}
		if ( tab === 'desktop' || tab === 'tablet' || tab === 'mobile' ) {
			buildViewportPanel( panel, tab, report, all );
			return;
		}
		var selected = all.filter( function ( difference ) {
			return CATEGORY_TABS[ difference.category ] === tab;
		} );
		appendDifferenceList( panel, selected );
	}

	function buildOverviewPanel( panel, report ) {
		var metrics = report.metrics && typeof report.metrics === 'object' ? report.metrics : {};
		var groups = metrics.groups && typeof metrics.groups === 'object' ? metrics.groups : {};
		var overall = metrics.overall && typeof metrics.overall === 'object' ? metrics.overall : {};
		var summary = report.summary && typeof report.summary === 'object' ? report.summary : {};
		var source = report.source && typeof report.source === 'object' ? report.source : {};
		var generated = report.generated && typeof report.generated === 'object' ? report.generated : {};

		panel.appendChild( createElement( 'h5', '', text( 'valTitle', 'Visual Validation' ) ) );

		var grid = createElement( 'div', 'replicaforge-stat-grid' );
		addStat( grid, 'Source', source.url ? source.url : '\u2014' );
		addStat( grid, 'Generated', generated.draft_id ? '#' + generated.draft_id + ' \u00b7 ' + ( generated.title || '' ) : '\u2014' );
		addStat( grid, 'Validation similarity', formatScore( overall.value ) );
		addStat( grid, 'Sections', ( typeof summary.source_sections === 'number' ? summary.source_sections : 0 ) + ' \u2192 ' + ( typeof summary.generated_sections === 'number' ? summary.generated_sections : 0 ) );
		addStat( grid, 'Components', ( typeof summary.source_components === 'number' ? summary.source_components : 0 ) + ' \u2192 ' + ( typeof summary.generated_components === 'number' ? summary.generated_components : 0 ) );
		addStat( grid, 'Mapped elements', typeof summary.mapped_components === 'number' ? summary.mapped_components : 0 );
		panel.appendChild( grid );

		panel.appendChild( createElement( 'p', 'replicaforge-card__note', text( 'valMetricsNote', 'Internal validation measurement based on the configured comparison metrics.' ) ) );

		panel.appendChild( createElement( 'h5', '', text( 'valGroups', 'Metrics' ) ) );
		var table = createElement( 'dl', 'replicaforge-val__metrics' );
		Object.keys( groups ).forEach( function ( group ) {
			var record = groups[ group ] || {};
			table.appendChild( createElement( 'dt', '', groupLabel( group ) ) );
			table.appendChild( createElement( 'dd', '', formatScore( record.value ) + ' \u00b7 not comparable: ' + ( typeof record.not_comparable === 'number' ? record.not_comparable : 0 ) ) );
		} );
		panel.appendChild( table );

		panel.appendChild( createElement( 'h5', '', text( 'valLevels', 'Comparison levels' ) ) );
		var levels = report.levels && typeof report.levels === 'object' ? report.levels : {};
		var levelList = createElement( 'ul', 'replicaforge-val__list' );
		Object.keys( levels ).forEach( function ( level ) {
			levelList.appendChild( createElement( 'li', '', level + ' \u00b7 ' + levels[ level ] ) );
		} );
		panel.appendChild( levelList );

		buildCorrectionPlanPanel( panel, report );
	}

	function buildCorrectionPlanPanel( panel, report ) {
		var plan = report.correction_plan && typeof report.correction_plan === 'object' ? report.correction_plan : {};
		panel.appendChild( createElement( 'h5', '', text( 'valPlanTitle', 'Machine-readable correction plan' ) ) );
		panel.appendChild( createElement( 'p', 'replicaforge-card__note', text( 'valReadOnly', 'Validation never modifies the Elementor document.' ) + ' ' + text( 'valPlanNote', 'Data only.' ) ) );

		var corrections = Array.isArray( plan.corrections ) ? plan.corrections : [];
		if ( corrections.length === 0 ) {
			panel.appendChild( createElement( 'p', 'replicaforge-empty', text( 'valNoPlan', 'No applicable corrections were detected.' ) ) );
			return;
		}

		var list = createElement( 'ul', 'replicaforge-val__plan' );
		corrections.slice( 0, 60 ).forEach( function ( correction ) {
			var reference = correction.generated_reference && correction.generated_reference.elementor_element_id ?
				correction.generated_reference.elementor_element_id :
				( correction.target || '' );
			list.appendChild( createElement( 'li', '', reference + ' \u00b7 ' + ( correction.property || '' ) + ' \u00b7 ' + String( correction.from ) + ' \u2192 ' + String( correction.to ) + ' \u00b7 ' + ( correction.severity || '' ) ) );
		} );
		panel.appendChild( list );
	}

	function buildViewportPanel( panel, viewport, report, all ) {
		var record = report.viewports && report.viewports[ viewport ] ? report.viewports[ viewport ] : {};
		var label = record.label ? record.label : viewport.charAt( 0 ).toUpperCase() + viewport.slice( 1 );
		panel.appendChild( createElement( 'h5', '', label ) );

		var visual = record.visual && typeof record.visual === 'object' ? record.visual : {};
		if ( visual.available === true ) {
			panel.appendChild( createElement( 'p', 'replicaforge-val__similarity', text( 'valVisualTitle', 'Rendered comparison' ) + ': ' + formatScore( typeof visual.similarity === 'number' ? visual.similarity * 100 : null ) ) );
			panel.appendChild( createElement( 'p', 'replicaforge-card__note', 'Differing pixels: ' + ( typeof visual.differing_ratio === 'number' ? Math.round( visual.differing_ratio * 1000 ) / 10 + '%' : '\u2014' ) + ' \u00b7 band: ' + ( visual.band || '\u2014' ) ) );
			var regions = Array.isArray( visual.regions ) ? visual.regions : [];
			panel.appendChild( createElement( 'h5', '', text( 'valVisualRegions', 'Differing regions' ) ) );
			if ( regions.length === 0 ) {
				panel.appendChild( createElement( 'p', 'replicaforge-empty', text( 'empty', 'No values were found.' ) ) );
			} else {
				var regionList = createElement( 'ul', 'replicaforge-data-list' );
				regions.forEach( function ( region ) {
					regionList.appendChild( createElement( 'li', '', 'band ' + region.band_size + 'px \u00b7 ' + Math.round( region.ratio * 100 ) + '% notable \u00b7 ' + region.severity ) );
				} );
				panel.appendChild( regionList );
			}
		} else {
			panel.appendChild( createElement( 'p', 'replicaforge-empty', text( 'valVisualNote', 'Rendered comparison is unavailable for this viewport.' ) + ( visual.reason ? ' ' + visual.reason : '' ) ) );
		}

		var selected = all.filter( function ( difference ) {
			var device = difference.viewport ? difference.viewport : 'desktop';
			return device === 'desktop' || device === viewport;
		} );
		appendDifferenceList( panel, selected );
	}

	function buildWarningsPanel( panel, report ) {
		panel.appendChild( createElement( 'h5', '', text( 'valWarnings', 'Warnings' ) ) );
		appendTextList( panel, Array.isArray( report.warnings ) ? report.warnings : [], text( 'empty', 'No values were found.' ), 'replicaforge-data-list' );

		panel.appendChild( createElement( 'h5', '', text( 'valLimitations', 'Limitations of this validation' ) ) );
		appendTextList( panel, Array.isArray( report.limitations ) ? report.limitations : [], text( 'empty', 'No values were found.' ), 'replicaforge-data-list' );

		var ai = report.ai && typeof report.ai === 'object' ? report.ai : {};
		panel.appendChild( createElement( 'h5', '', text( 'valAiTitle', 'AI explanation' ) ) );
		if ( ai.available === true ) {
			panel.appendChild( createElement( 'p', '', ai.explanation ? ai.explanation : '' ) );
			panel.appendChild( createElement( 'p', 'replicaforge-card__note', text( 'valAiNote', 'The model may only restate measured differences.' ) ) );
			var recommendationLines = ( Array.isArray( ai.recommendations ) ? ai.recommendations : [] ).map( function ( recommendation ) {
				return ( recommendation.target || '' ) + ' \u00b7 ' + ( recommendation.property || '' ) + ' \u00b7 ' + String( recommendation.from ) + ' \u2192 ' + String( recommendation.to ) + ' \u00b7 ' + ( recommendation.action || '' );
			} );
			appendTextList( panel, recommendationLines, text( 'empty', 'No values were found.' ), 'replicaforge-data-list' );
		} else {
			appendTextList( panel, Array.isArray( ai.warnings ) ? ai.warnings : [ text( 'valNotComparable', 'Not comparable' ) ], text( 'empty', 'No values were found.' ), 'replicaforge-data-list' );
		}
	}

	function appendDifferenceList( panel, differences ) {
		panel.appendChild( createElement( 'h5', '', text( 'valDifferences', 'Detected differences' ) ) );

		if ( ! Array.isArray( differences ) || differences.length === 0 ) {
			panel.appendChild( createElement( 'p', 'replicaforge-empty', text( 'valNoDifferences', 'No differences were detected.' ) ) );
			return;
		}

		var list = createElement( 'ul', 'replicaforge-val__differences' );
		differences.slice( 0, 100 ).forEach( function ( difference ) {
			var item = createElement( 'li', 'replicaforge-val__difference' );
			item.appendChild( createElement( 'span', 'replicaforge-badge replicaforge-badge--' + ( difference.severity || 'informational' ), difference.severity || 'informational' ) );
			item.appendChild( createElement( 'span', 'replicaforge-val__category', difference.category || '' ) );
			item.appendChild( createElement( 'span', 'replicaforge-val__message', difference.message ? difference.message : ( difference.target || '' ) + ' \u00b7 ' + ( difference.property || '' ) ) );
			item.appendChild( createElement( 'span', 'replicaforge-val__values', formatValue( difference.expected ) + ' \u2192 ' + formatValue( difference.actual ) + ' (delta ' + formatValue( difference.difference ) + ')' ) );
			list.appendChild( item );
		} );
		panel.appendChild( list );

		if ( differences.length > 100 ) {
			panel.appendChild( createElement( 'p', 'replicaforge-card__note', differences.length + ' differences total. Export the report to read the full list.' ) );
		}
	}

	function formatValue( value ) {
		if ( value === null || typeof value === 'undefined' || value === '' ) {
			return '\u2014';
		}
		if ( typeof value === 'object' ) {
			return JSON.stringify( value );
		}
		return String( value );
	}

	function exportValidation( format ) {
		if ( ! currentValidation || ! currentValidation.validation_id || ! config.validateEndpoint || ! config.nonce ) {
			return;
		}
		var endpoint = config.validateEndpoint + '/' + encodeURIComponent( currentValidation.validation_id ) + '/export?format=' + encodeURIComponent( format );
		fetch( endpoint, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'X-WP-Nonce': config.nonce || '' }
		} ).then( function ( response ) {
			return response.json().catch( function () { return null; } ).then( function ( payload ) {
				if ( ! response.ok || ! payload || payload.success !== true ) {
					throw new Error( extractError( payload ) );
				}
				return payload.data;
			} );
		} ).then( function ( payload ) {
			if ( format === 'csv' ) {
				downloadText( payload.filename, payload.content, 'text/csv' );
				return;
			}
			downloadText( payload.filename, JSON.stringify( payload.data, null, 2 ), 'application/json' );
		} ).catch( function ( error ) {
			setPhase5Error( error && error.message ? error.message : text( 'valFailed', 'The export failed.' ) );
		} );
	}

	function downloadText( filename, content, type ) {
		if ( typeof window.Blob === 'function' && typeof window.URL !== 'undefined' && typeof window.URL.createObjectURL === 'function' ) {
			var blob = new window.Blob( [ content ], { type: type } );
			var url = window.URL.createObjectURL( blob );
			var link = document.createElement( 'a' );
			link.href = url;
			link.download = filename;
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			window.URL.revokeObjectURL( url );
			return;
		}
		window.open( 'data:text/plain;charset=utf-8,' + encodeURIComponent( content ), '_blank', 'noopener' );
	}


	function clearPhase6State() {
		currentPlan = null;
		currentValidationId = '';
		correctionInFlight = false;
		setHidden( phase6Review, true );
		setHidden( phase6Result, true );
		setHidden( phase6Hint, true );
		if ( phase6Error ) {
			phase6Error.textContent = '';
			phase6Error.hidden = true;
		}
		if ( phase6Notice ) {
			phase6Notice.textContent = '';
			setHidden( phase6Notice, true );
		}
		if ( phase6Status ) {
			phase6Status.textContent = '';
		}
		if ( phase6PlanId ) {
			phase6PlanId.textContent = '';
		}
		if ( phase6Counts ) {
			clearNode( phase6Counts );
		}
		if ( phase6Groups ) {
			clearNode( phase6Groups );
		}
		if ( phase6Summary ) {
			clearNode( phase6Summary );
		}
		if ( phase6Panels ) {
			clearNode( phase6Panels );
		}
		if ( phase6PlanButton ) {
			phase6PlanButton.disabled = true;
		}
	}

	function setPhase6Error( message ) {
		if ( ! phase6Error ) {
			return;
		}
		phase6Error.textContent = typeof message === 'string' && message !== '' ? message : text( 'corFailedMessage', 'The corrections could not be applied. The previous document was preserved.' );
		phase6Error.hidden = false;
	}

	function renderPhase6Availability() {
		if ( ! phase6Panel ) {
			return;
		}

		var hasValidation = typeof currentValidationId === 'string' && currentValidationId !== '';
		var hasDraft = typeof currentDraftId === 'number' && currentDraftId > 0;

		setHidden( phase6Panel, false );

		if ( phase6Description ) {
			phase6Description.textContent = text( 'corIntro', 'Review the measurable differences and apply the safe ones.' );
		}
		if ( phase6AiNote ) {
			var aiEnabled = config.ai && config.ai.enabled === true;
			phase6AiNote.textContent = text( 'corAiNote', 'The model may only reorder corrections that were already measured.' ) +
				' \u00b7 ' + ( aiEnabled ? 'AI configured' : 'AI not configured' );
		}
		if ( phase6Ai ) {
			phase6Ai.disabled = ! ( config.ai && config.ai.enabled === true );
		}

		if ( ! hasValidation ) {
			if ( phase6Notice ) {
				phase6Notice.textContent = text( 'corNoValidation', 'Run a validation first.' );
				setHidden( phase6Notice, false );
			}
		} else if ( ! hasDraft ) {
			if ( phase6Notice ) {
				phase6Notice.textContent = text( 'corNoDraft', 'Generate an Elementor draft first.' );
				setHidden( phase6Notice, false );
			}
		} else {
			setHidden( phase6Notice, true );
		}

		if ( phase6PlanButton ) {
			phase6PlanButton.disabled = ! ( hasValidation && hasDraft ) || correctionInFlight === true;
		}
		if ( phase6Status ) {
			phase6Status.textContent = hasValidation ? currentValidationId : '';
		}
	}

	function requestCorrectionPlan() {
		if ( correctionInFlight || ! config.planCorrectionsEndpoint || ! config.nonce ) {
			return;
		}
		if ( ! currentValidationId || currentDraftId < 1 ) {
			return;
		}

		correctionInFlight = true;
		setHidden( phase6Review, true );
		setHidden( phase6Result, true );
		if ( phase6Error ) {
			phase6Error.textContent = '';
			phase6Error.hidden = true;
		}
		if ( phase6PlanButton ) {
			phase6PlanButton.disabled = true;
		}
		if ( phase6Status ) {
			phase6Status.textContent = text( 'corPlanning', 'Planning corrections...' );
		}

		var body = {
			validation_id: currentValidationId,
			draft_id: currentDraftId,
			ai: phase6Ai ? phase6Ai.checked === true : false
		};

		fetch( config.planCorrectionsEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-WP-Nonce': config.nonce || '' },
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().catch( function () { return null; } ).then( function ( payload ) {
				if ( ! response.ok || ! payload || payload.success !== true || ! payload.data ) {
					throw new Error( extractError( payload ) );
				}
				return payload.data;
			} );
		} ).then( renderCorrectionPlan ).catch( function ( error ) {
			if ( phase6Status ) {
				phase6Status.textContent = '';
			}
			setPhase6Error( error && error.message ? error.message : text( 'corPlanFailed', 'The correction plan could not be produced.' ) );
		} ).then( function () {
			correctionInFlight = false;
			renderPhase6Availability();
		} );
	}

	function renderCorrectionPlan( plan ) {
		if ( ! phase6Review || ! plan ) {
			return;
		}

		currentPlan = plan;
		currentCorrectionId = '';
		setHidden( phase6Review, false );
		setHidden( phase6Result, true );

		if ( phase6PlanId ) {
			phase6PlanId.textContent = plan.plan_id || '';
		}
		if ( phase6Counts ) {
			clearNode( phase6Counts );
			addStat( phase6Counts, text( 'corSafe', 'Safe' ), plan.counts && typeof plan.counts.safe === 'number' ? plan.counts.safe : 0 );
			addStat( phase6Counts, text( 'corReview', 'Review required' ), plan.counts && typeof plan.counts.requires_review === 'number' ? plan.counts.requires_review : 0 );
			addStat( phase6Counts, text( 'corBlocked', 'Blocked' ), plan.counts && typeof plan.counts.blocked === 'number' ? plan.counts.blocked : 0 );
			addStat( phase6Counts, text( 'corLevel', 'Level' ), typeof plan.levels_used === 'number' ? plan.levels_used : 0 );
			addStat( phase6Counts, text( 'valValidation', 'Validation' ), formatScore( plan.validation_score ) );
		}

		if ( phase6Groups ) {
			clearNode( phase6Groups );
			appendCorrectionGroup( phase6Groups, text( 'corSafe', 'Safe' ), plan.safe || [], 'safe' );
			appendCorrectionGroup( phase6Groups, text( 'corReview', 'Review required' ), plan.requires_review || [], 'requires_review' );
			appendCorrectionGroup( phase6Groups, text( 'corBlocked', 'Blocked' ), plan.blocked || [], 'blocked' );
		}

		if ( phase6ApplySafe ) {
			phase6ApplySafe.disabled = ! ( plan.counts && plan.counts.safe > 0 );
		}
		if ( phase6ApplySelected ) {
			phase6ApplySelected.disabled = false;
		}
	}

	function appendCorrectionGroup( parent, label, corrections, eligibility ) {
		parent.appendChild( createElement( 'h5', 'replicaforge-phase6__group-title', label + ' (' + ( Array.isArray( corrections ) ? corrections.length : 0 ) + ')' ) );
		if ( ! Array.isArray( corrections ) || corrections.length === 0 ) {
			parent.appendChild( createElement( 'p', 'replicaforge-empty', text( 'empty', 'No values were found.' ) ) );
			return;
		}

		var list = createElement( 'ul', 'replicaforge-val__corrections' );
		corrections.forEach( function ( correction ) {
			var item = createElement( 'li', 'replicaforge-val__correction replicaforge-val__correction--' + eligibility );
			var head = createElement( 'p', 'replicaforge-val__correction-head' );

			if ( 'blocked' !== eligibility ) {
				var box = document.createElement( 'input' );
				box.type = 'checkbox';
				box.className = 'replicaforge-val__check';
				box.value = correction.correction_id;
				box.setAttribute( 'data-eligibility', correction.eligibility || eligibility );
				box.checked = 'safe' === eligibility;
				if ( 'safe' !== eligibility && ! correction.auto ) {
					box.disabled = false;
				}
				head.appendChild( box );
			}

			head.appendChild( createElement( 'span', 'replicaforge-val__correction-title', correction.target && correction.target.elementor_element_id ? correction.target.elementor_element_id : ( correction.target_label || 'document' ) ) );
			head.appendChild( createElement( 'span', 'replicaforge-badge replicaforge-badge--' + ( correction.severity || 'informational' ), 'L' + ( correction.level || 0 ) ) );
			list.appendChild( item );
			item.appendChild( head );

			var grid = createElement( 'dl', 'replicaforge-val__facts' );
			[
				[ 'corProperty', correction.property_label || correction.property || '' ],
				[ 'corCurrent', correction.current_document_label || correction.current_label || '\u2014' ],
				[ 'corSource', correction.expected_label || '\u2014' ],
				[ 'corDifference', formatValue( correction.difference ) ],
				[ 'corViewport', correction.viewport || 'desktop' ],
				[ 'corConfidence', Math.round( ( typeof correction.confidence === 'number' ? correction.confidence : 0 ) * 100 ) + '%' ],
				[ 'corElement', ( correction.target && correction.target.source_component_id ) || '\u2014' ],
				[ 'corControl', correction.control || '\u2014' ]
			].forEach( function ( pair ) {
				grid.appendChild( createElement( 'dt', '', text( pair[ 0 ], pair[ 0 ] ) ) );
				grid.appendChild( createElement( 'dd', '', String( pair[ 1 ] ) ) );
			} );
			item.appendChild( grid );

			if ( correction.manually_modified === true ) {
				item.appendChild( createElement( 'p', 'replicaforge-val__conflict', text( 'corManualNote', 'This property was changed after generation.' ) ) );
			}
			if ( correction.reason ) {
				item.appendChild( createElement( 'p', 'replicaforge-val__reason', correction.reason ) );
			}
		} );
		parent.appendChild( list );
	}

	function selectedCorrections() {
		if ( ! phase6Groups ) {
			return [];
		}
		var ids = [];
		phase6Groups.querySelectorAll( '.replicaforge-val__check' ).forEach( function ( box ) {
			if ( box.checked === true ) {
				ids.push( box.value );
			}
		} );
		return ids;
	}

	function confirmApply( selected ) {
		if ( ! window.confirm( text( 'corConfirm', 'The current Elementor draft will be snapshotted before anything is written.' ) ) ) {
			return false;
		}
		applySelectedCorrections( selected );
		return true;
	}

	function applySelectedCorrections( selected ) {
		if ( ! selected || selected.length === 0 || ! currentPlan || ! config.applyCorrectionsEndpoint || ! config.nonce ) {
			if ( selected && selected.length === 0 ) {
				window.alert( text( 'corCancelled', 'Nothing was selected, so nothing was changed.' ) );
			}
			return;
		}

		correctionInFlight = true;
		setHidden( phase6Review, true );
		if ( phase6Error ) {
			phase6Error.textContent = '';
			phase6Error.hidden = true;
		}
		if ( phase6Status ) {
			phase6Status.textContent = text( 'corApplying', 'Applying corrections...' );
		}

		var body = {
			plan_id: currentPlan.plan_id,
			selected: selected,
			approved_structural: phase6Structural ? phase6Structural.checked === true : false,
			revalidate: true,
			iterations: 1,
			design_representation: currentRepresentation
		};

		fetch( config.applyCorrectionsEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-WP-Nonce': config.nonce || '' },
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().catch( function () { return null; } ).then( function ( payload ) {
				if ( ! response.ok || ! payload || payload.success !== true || ! payload.data ) {
					throw new Error( extractError( payload ) );
				}
				return payload.data;
			} );
		} ).then( renderCorrectionRun ).catch( function ( error ) {
			if ( phase6Status ) {
				phase6Status.textContent = '';
			}
			setPhase6Error( error && error.message ? error.message : text( 'corFailedMessage', 'The corrections could not be applied. The previous document was preserved.' ) );
		} ).then( function () {
			correctionInFlight = false;
			renderPhase6Availability();
		} );
	}

	function renderCorrectionRun( run ) {
		if ( ! phase6Result || ! run ) {
			return;
		}
		setHidden( phase6Result, false );
		currentCorrectionId = run.correction_id || '';

		if ( phase6Summary ) {
			clearNode( phase6Summary );
			addStat( phase6Summary, text( 'corBefore', 'Before' ), formatScore( run.validation_before ) );
			addStat( phase6Summary, text( 'corAfter', 'After' ), formatScore( run.validation_after ) );
			addStat( phase6Summary, text( 'corImprovement', 'Measured change' ), run.improvement === null || typeof run.improvement !== 'number' ? '\u2014' : ( ( run.improvement > 0 ? '+' : '' ) + run.improvement ) );
			addStat( phase6Summary, text( 'corApplied', 'Applied' ), run.counts && typeof run.counts.applied === 'number' ? run.counts.applied : ( typeof run.applied === 'number' ? run.applied : 0 ) );
			addStat( phase6Summary, text( 'corRejected', 'Rejected' ), run.counts && typeof run.counts.rejected === 'number' ? run.counts.rejected : 0 );
			addStat( phase6Summary, text( 'corFailed', 'Failed' ), run.counts && typeof run.counts.failed === 'number' ? run.counts.failed : 0 );
			addStat( phase6Summary, text( 'corRegressions', 'Regressions' ), typeof run.regression_count === 'number' ? run.regression_count : 0 );
			addStat( phase6Summary, text( 'corStatus', 'Status' ), run.status || ( run.rolled_back === true ? 'rolled_back' : 'completed' ) );
		}

		if ( phase6Measurement ) {
			phase6Measurement.textContent = run.measurement_note || text( 'corMeasurementNote', 'Internal validation measurement.' );
		}

		if ( phase6Panels ) {
			clearNode( phase6Panels );
			phase6Panels.appendChild( createElement( 'h5', '', text( 'corChanges', 'Changes applied' ) ) );
			var changes = Array.isArray( run.changes ) ? run.changes : [];
			if ( changes.length === 0 ) {
				phase6Panels.appendChild( createElement( 'p', 'replicaforge-empty', text( 'corNoChanges', 'No corrections were applied.' ) ) );
			} else {
				var table = createElement( 'table', 'widefat striped replicaforge-val__table' );
				var head = createElement( 'tr' );
				[ 'Element', 'Property', 'Viewport', 'From', 'To' ].forEach( function ( heading ) {
					head.appendChild( createElement( 'th', '', heading ) );
				} );
				table.appendChild( head );
				changes.forEach( function ( change ) {
					var row = createElement( 'tr' );
					row.appendChild( createElement( 'td', '', change.element_id || '\u2014' ) );
					row.appendChild( createElement( 'td', '', change.property || '\u2014' ) );
					row.appendChild( createElement( 'td', '', change.viewport || 'desktop' ) );
					row.appendChild( createElement( 'td', '', change.old_value || '\u2014' ) );
					row.appendChild( createElement( 'td', '', change.new_value || '\u2014' ) );
					table.appendChild( row );
				} );
				phase6Panels.appendChild( table );
			}

			if ( Array.isArray( run.regressions ) && run.regressions.length > 0 ) {
				phase6Panels.appendChild( createElement( 'h5', '', text( 'corRegressions', 'Regressions' ) ) );
				appendTextList( phase6Panels, run.regressions.map( function ( regression ) {
					return regression.scope + ': ' + regression.before + ' \u2192 ' + regression.after + ' (' + regression.change + ')';
				} ), text( 'empty', 'No values were found.' ), 'replicaforge-data-list' );
			}

			if ( run.stop_reason_text ) {
				phase6Panels.appendChild( createElement( 'p', 'replicaforge-card__note', run.stop_reason_text ) );
			}
		}

		assignLink( phase6Elementor, run.elementor_edit_url || elementorEditUrl( currentDraftId ) );
		assignLink( phase6Preview, run.preview_url || draftPreviewUrl( currentDraftId ) );
	}

	function exportCorrections( kind, format ) {
		if ( ! config.exportCorrectionsEndpoint || ! config.nonce ) {
			return;
		}
		var identifier = 'plan' === kind ? ( currentPlan ? currentPlan.plan_id : '' ) : ( currentCorrectionId || '' );
		if ( ! identifier ) {
			return;
		}
		var body = {
			kind: kind,
			format: format,
			draft_id: currentDraftId,
			plan_id: 'plan' === kind ? identifier : '',
			correction_id: 'run' === kind ? identifier : ''
		};
		fetch( config.exportCorrectionsEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-WP-Nonce': config.nonce || '' },
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().catch( function () { return null; } ).then( function ( payload ) {
				if ( ! response.ok || ! payload || payload.success !== true ) {
					throw new Error( extractError( payload ) );
				}
				return payload.data;
			} );
		} ).then( function ( payload ) {
			downloadText( payload.filename, 'csv' === format ? payload.content : JSON.stringify( payload.data, null, 2 ), 'csv' === format ? 'text/csv' : 'application/json' );
		} ).catch( function ( error ) {
			setPhase6Error( error && error.message ? error.message : text( 'corExportFailed', 'The export failed.' ) );
		} );
	}

	function elementorEditUrl( postId ) {
		if ( ! postId ) {
			return '';
		}
		return config.validationUrl ? config.validationUrl.replace( /page=replicaforge-validation.*$/, '' ) + 'post.php?post=' + postId + '&action=elementor' : '';
	}

	function draftPreviewUrl( postId ) {
		if ( ! postId ) {
			return '';
		}
		return config.validationUrl ? config.validationUrl.replace( /page=replicaforge-validation.*$/, '' ) + 'post.php?post=' + postId + '&action=preview' : '';
	}

	function extractError( payload ) {
		if ( payload && payload.error && typeof payload.error.message === 'string' ) {
			return payload.error.message;
		}
		if ( payload && typeof payload.message === 'string' ) {
			return payload.message;
		}
		return text( 'requestFailed', 'The analysis could not be completed. Please try again.' );
	}

	function requestAnalysis( url ) {
		return fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-WP-Nonce': config.nonce || ''
			},
			body: JSON.stringify( { url: url } )
		} ).then( function ( response ) {
			return response.json().catch( function () { return null; } ).then( function ( payload ) {
				if ( ! response.ok || ! payload || payload.success !== true || ! payload.data ) {
					throw new Error( extractError( payload ) );
				}
				return payload.data;
			} );
		} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		clearError();
		setHidden( results, true );
		setHidden( rawDetails, true );
		setHidden( designUnderstanding, true );
		setHidden( representationRaw, true );
		setHidden( aiPanel, true );
		setHidden( aiPlan, true );
		currentRepresentation = null;
		currentSourceUrl = '';
		clearAiError();
		clearPhase4State();
		setHidden( phase4Panel, true );
		clearPhase5State();
		setHidden( phase5Panel, true );
		clearPhase6State();
		setHidden( phase6Panel, true );

		var url = input.value.trim();
		if ( ! url || ! /^https?:\/\//i.test( url ) ) {
			setError( text( 'invalidUrl', 'Enter a valid http:// or https:// website URL.' ) );
			input.focus();
			return;
		}

		if ( ! config.endpoint || ! config.nonce ) {
			setError( text( 'requestFailed', 'The analysis could not be completed. Please try again.' ) );
			return;
		}

		setLoading( true );
		setStatus( text( 'analyzing', 'Analyzing website...' ), 'loading' );
		var hint = text( 'analyzingHint', 'Fetching the public page and building a structured analysis.' );
		status.setAttribute( 'aria-label', hint );
		if ( statusHint ) {
			statusHint.textContent = hint;
			setHidden( statusHint, false );
		}

		requestAnalysis( url ).then( function ( data ) {
			renderResults( data );
			setStatus( text( 'completed', 'Analysis completed.' ), 'complete' );
		} ).catch( function ( error ) {
			setError( error && error.message ? error.message : text( 'requestFailed', 'The analysis could not be completed. Please try again.' ) );
		} ).then( function () {
			setLoading( false );
		} );
	} );

	if ( aiRunButton ) {
		aiRunButton.addEventListener( 'click', function () {
			requestAiPlan();
		} );
	}

	if ( phase4RunButton ) {
		phase4RunButton.addEventListener( 'click', function () {
			requestGeneration();
		} );
	}

	if ( phase5RunButton ) {
		phase5RunButton.addEventListener( 'click', function () {
			requestValidation();
		} );
	}

	if ( phase5ExportJson ) {
		phase5ExportJson.addEventListener( 'click', function () {
			exportValidation( 'json' );
		} );
	}

	if ( phase5ExportCsv ) {
		phase5ExportCsv.addEventListener( 'click', function () {
			exportValidation( 'csv' );
		} );
	}

	if ( phase6PlanButton ) {
		phase6PlanButton.addEventListener( 'click', function () {
			requestCorrectionPlan();
		} );
	}

	if ( phase6SelectAll ) {
		phase6SelectAll.addEventListener( 'click', function () {
			phase6Groups.querySelectorAll( '.replicaforge-val__check' ).forEach( function ( box ) {
				if ( box.getAttribute( 'data-eligibility' ) === 'safe' ) {
					box.checked = true;
				}
			} );
		} );
	}

	if ( phase6SelectNone ) {
		phase6SelectNone.addEventListener( 'click', function () {
			phase6Groups.querySelectorAll( '.replicaforge-val__check' ).forEach( function ( box ) {
				box.checked = false;
			} );
		} );
	}

	if ( phase6ApplySafe ) {
		phase6ApplySafe.addEventListener( 'click', function () {
			confirmApply( selectedCorrections().filter( function ( id ) {
				return id !== '';
			} ) );
		} );
	}

	if ( phase6ApplySelected ) {
		phase6ApplySelected.addEventListener( 'click', function () {
			confirmApply( selectedCorrections() );
		} );
	}

	if ( phase6ExportPlan ) {
		phase6ExportPlan.addEventListener( 'click', function () {
			exportCorrections( 'plan', 'json' );
		} );
	}

	if ( phase6ExportPlanCsv ) {
		phase6ExportPlanCsv.addEventListener( 'click', function () {
			exportCorrections( 'plan', 'csv' );
		} );
	}

	if ( phase6ExportRun ) {
		phase6ExportRun.addEventListener( 'click', function () {
			exportCorrections( 'run', 'json' );
		} );
	}

	if ( phase6Validate ) {
		phase6Validate.addEventListener( 'click', function () {
			currentValidationId = '';
			clearPhase6State();
			requestValidation();
		} );
	}

}() );
