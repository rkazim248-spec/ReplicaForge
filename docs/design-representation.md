# ReplicaForge Design Representation 2.0

This document defines the deterministic intermediate representation produced by Phase 2. It is an analysis contract, not an Elementor document and not a rendering specification.

## Purpose

Phase 1 answers: **what HTML and basic frontend data are present?**

Phase 2 answers: **what page regions, sections, components, layout patterns, design tokens, and responsive signals can be inferred from the static frontend?**

The representation is returned at:

```text
data.design_representation
```

A future generator should consume this contract rather than fetch or parse the target HTML again.

## Top-level schema

```json
{
  "schema_version": "2.0",
  "page": {},
  "layout": {},
  "sections": [],
  "components": [],
  "hierarchy": {
    "primary": [],
    "secondary": [],
    "supporting": [],
    "cta": [],
    "decorative": []
  },
  "design_system": {},
  "responsive": {},
  "assets": {},
  "confidence": {},
  "warnings": [],
  "analysis": {}
}
```

### `page`

Directly derived from the Phase 1 result.

```json
{
  "url": "https://example.com/",
  "final_url": "https://example.com/",
  "title": "Example Store",
  "type": "E-commerce",
  "type_confidence": 0.82,
  "type_source": "inferred",
  "language": "en",
  "description": "Example description",
  "http_status": 200
}
```

`type` is a Phase 1 heuristic. It is not a claim that the website is visually or commercially accurate.

### `layout`

```json
{
  "page": {
    "type": "stack",
    "display": null,
    "direction": null,
    "alignment": {
      "horizontal": null,
      "vertical": null
    },
    "wrap": null,
    "gap": null,
    "grid_columns": null,
    "grid_rows": null,
    "column_count": 0,
    "columns": [],
    "container": {
      "width": null,
      "max_width": "1200px",
      "centered": true
    },
    "source": {
      "node_id": "node_00007",
      "tag": "body",
      "selector": "html:nth-of-type(1) > body:nth-of-type(1)"
    },
    "confidence": 0.2,
    "source_type": "inferred"
  },
  "regions": [
    {
      "id": "region_001",
      "type": "header",
      "order": 0,
      "confidence": 0.9,
      "source": {},
      "node_id": "node_00010"
    }
  ]
}
```

Layout analysis is static and structural. It does not calculate rendered dimensions, inherited styles, or browser layout.

### `sections`

Sections are ordered and uniquely identified.

```json
{
  "id": "section_005",
  "type": "hero",
  "order": 5,
  "confidence": 0.96,
  "source": {
    "node_id": "node_00016",
    "tag": "section",
    "selector": "html:nth-of-type(1) > body:nth-of-type(1) > main:nth-of-type(1) > section:nth-of-type(1)"
  },
  "signals": [
    "semantic_section",
    "class_or_id_hint",
    "heading",
    "supporting_paragraph",
    "image",
    "call_to_action"
  ],
  "content": {
    "heading": "Build Better Websites",
    "text": "Supporting text...",
    "paragraph_count": 1,
    "link_count": 1,
    "image_count": 1,
    "button_count": 0
  },
  "children": [],
  "components": ["component_005", "component_006"],
  "layout": {},
  "styles": {}
}
```

Common section types include:

```text
header
navigation
main
hero
content
features
services
about
products
product_grid
categories
testimonials
reviews
pricing
faq
statistics
team
portfolio
gallery
blog
news
contact
newsletter
cta
sidebar
footer
section
article
```

A generic wrapper is not promoted to a page-level section merely because it has many children. Individual card-like nodes are normally represented as components and repeated card groups instead.

### `components`

Components are bounded, unique, and source-traceable.

```json
{
  "id": "component_001",
  "type": "product_card",
  "role": "repeated_or_content_card",
  "text": "Product$19.00Add to Cart",
  "source": {
    "node_id": "node_00021",
    "tag": "div",
    "selector": "html:nth-of-type(1) > body:nth-of-type(1) > main:nth-of-type(1) > section:nth-of-type(2) > div:nth-of-type(1)"
  },
  "section_id": "section_007",
  "confidence": 0.84,
  "source_type": "detected",
  "attributes": {},
  "styles": {},
  "layout": {
    "child_count": 4
  },
  "children": [],
  "fields": {
    "image": "https://example.com/product.jpg",
    "title": "Product",
    "price": "$19.00",
    "sale_price": null,
    "rating": null,
    "badge": null,
    "button": "Add to Cart",
    "link": null
  }
}
```

Supported component types include:

```text
heading
paragraph
image
background_image
button
link
navigation_item
social_link
form
form_field
accordion
tabs
tab
list
icon
badge
card
product_card
pricing_card
testimonial_card
blog_card
feature_card
team_card
portfolio_card
card_group
```

#### Repeated structures

Repeated sibling cards are grouped without discarding the individual component records:

```json
{
  "id": "component_020",
  "type": "card_group",
  "role": "repeated_structure",
  "count": 4,
  "card_type": "product_card",
  "repeated_structure": true,
  "children": [
    "component_016",
    "component_017",
    "component_018",
    "component_019"
  ]
}
```

The group is a structural inference. It does not mean the source website used a component framework.

### `hierarchy`

The hierarchy is a conservative grouping of component IDs, not a rendered z-index or visual prominence calculation:

```json
{
  "primary": ["component_001"],
  "secondary": ["component_004", "component_008"],
  "supporting": ["component_002", "component_010"],
  "cta": ["component_003"],
  "decorative": ["component_006"]
}
```

A component can be absent from all groups when the evidence is insufficient. Later phases may interpret this structure using additional AI or rendering signals.

#### Product fields

Product fields are extracted only when the source contains evidence. Missing values remain `null`; prices, ratings, and discounts are never fabricated.

### `design_system`

```json
{
  "colors": {
    "tokens": [
      {
        "value": "#635bff",
        "usage_count": 12,
        "properties": ["background"],
        "button_background": true,
        "role": "background",
        "confidence": 0.7,
        "source": "inferred"
      }
    ],
    "by_role": {
      "primary": {
        "value": "#635bff",
        "role": "primary",
        "confidence": 0.62,
        "source": "inferred"
      }
    }
  },
  "typography": {
    "hierarchy": {
      "heading_1": {
        "role": "heading_1",
        "font_family": null,
        "font_size": "56px",
        "font_weight": null,
        "line_height": 1.1,
        "letter_spacing": null,
        "text_transform": null,
        "source": {},
        "confidence": 0.82,
        "source_type": "detected"
      },
      "body": {}
    },
    "families": [
      {
        "property": "font-family",
        "value": "Inter",
        "count": 4
      }
    ],
    "sizes": [],
    "weights": [],
    "line_heights": [],
    "letter_spacing": [],
    "text_transform": []
  },
  "font_families": [],
  "spacing": {
    "scale": ["8px", "16px", "24px", "32px"]
  },
  "radius": {
    "scale": ["4px", "8px", "9999px"]
  },
  "shadows": [
    {
      "value": "0 4px 20px rgba(0,0,0,.1)",
      "usage_count": 4,
      "property": "box-shadow"
    }
  ],
  "buttons": [],
  "containers": [],
  "layout_rules": [],
  "design_tokens": {}
}
```

Semantic color roles are candidates, not facts. Low-confidence roles remain explicitly marked as inferred.

The `design_tokens` member is a normalized convenience view of the detected color, typography, spacing, radius, shadow, container, and button families. The original family fields remain available alongside it.

### `responsive`

```json
{
  "viewport_meta": true,
  "media_queries_detected": true,
  "breakpoints": [
    {
      "value": "768px",
      "rules_count": 4,
      "queries": ["(max-width:768px)"]
    }
  ],
  "rules": [
    {
      "breakpoint": "768px",
      "query": "(max-width:768px)",
      "changes": [
        "layout_change",
        "spacing_change"
      ],
      "rule_count": 1,
      "confidence": 0.68,
      "source_type": "detected"
    }
  ],
  "mobile_navigation": {
    "detected": true,
    "behavior": "unknown",
    "confidence": 0.58,
    "evidence": [],
    "source_type": "detected"
  },
  "confidence": 0.72
}
```

Responsive changes are reported only when declarations or static attributes support them. JavaScript behavior is never guessed.

### `assets`

Only safe metadata is returned. ReplicaForge does not download images, fonts, or arbitrary resources in Phase 2.

```json
{
  "stylesheets": {
    "discovered_count": 4,
    "loaded": [
      {
        "url": "https://example.com/assets/site.css",
        "final_url": "https://example.com/assets/site.css",
        "bytes": 12345,
        "status": 200,
        "media": "all"
      }
    ],
    "fetched_css_bytes": 12345
  },
  "images": {
    "count": 12,
    "roles": {
      "hero_image": 1,
      "product_image": 4,
      "content_image": 7
    }
  },
  "fonts": [],
  "counts": {
    "images_not_downloaded": true,
    "javascript_executed": false,
    "raw_css_returned": false
  }
}
```

### `confidence`

```json
{
  "overall": 0.71,
  "sections": 0.76,
  "components": 0.82,
  "design_system": 0.68,
  "responsive": 0.72
}
```

Confidence is a bounded heuristic value, not a probability guarantee.

## Source traceability

Important inferred records contain a safe source object:

```json
{
  "node_id": "node_00021",
  "tag": "div",
  "selector": "html:nth-of-type(1) > body:nth-of-type(1) > main:nth-of-type(1) > section:nth-of-type(2) > div:nth-of-type(1)"
}
```

Selectors are generated from the sanitized DOM. They are intended for debugging and later mapping, not for execution against the target site. Raw remote HTML is not stored in the representation or rendered into the admin UI.

## Validation

Before returning the representation, ReplicaForge checks:

- Required top-level keys.
- `schema_version === "2.0"`.
- Bounded string lengths.
- Safe URL-shaped values.
- Unique section IDs.
- Unique component IDs.
- Valid section, component, and hierarchy references.
- Valid confidence ranges.
- Representation and resource limits.

If validation fails, ReplicaForge does not return the invalid representation. The `to_array()` contract method returns an empty array until validation succeeds, and the Phase 1 result remains available with a safe `phase2_error` object.

## Resource and security limits

| Resource | Limit |
| --- | ---: |
| Page response | 5 MiB |
| Page redirects | 3 |
| Page request budget | 15 seconds |
| Linked stylesheets | 3 |
| One stylesheet | 1 MiB |
| Combined stylesheets | 1.5 MiB |
| Aggregate stylesheet budget | 6 seconds |
| Per stylesheet timeout | 2 seconds |
| Inline CSS | 512 KiB |
| CSS parser input | 2 MiB |
| CSS selector length | 180 characters |
| DOM nodes | 12,000 |
| Sections | 100 |
| Components | 600 |
| Repeated card groups | 100 |
| CSS rules per parsed scope | 2,500 |

Every stylesheet request is validated again by the URL validator and uses manual, individually validated redirects. Discovered link/image references are filtered against the non-fetching public-reference policy; any future code that fetches one must repeat full DNS-aware validation immediately beforehand. CSS `@import` dependencies are not recursively downloaded. Plugin-level checks cannot by themselves eliminate DNS rebinding between validation and transport or replace network-level egress controls.

## Future consumption

Phase 3 may consume:

```text
data.design_representation
```

or inject a `Design_Representation_Contract` implementation. The Phase 3 AI layer:

1. Reads the representation only.
2. Preserves IDs and source references in its own output.
3. Adds its own uncertainty metadata.
4. Avoids treating low-confidence heuristics as facts.
5. Validates generated output before any later Elementor or WordPress workflow.

ReplicaForge does not create Elementor JSON, widgets, pages, or drafts.

## Phase 3 consumption boundary

Phase 3 consumes this representation through `Design_Representation_Contract`. The AI context builder selects and bounds structured records, redacts sensitive values, and never includes raw HTML or raw CSS. A future generator should consume the same contract rather than fetch or parse the target page again.

The Phase 3 output is documented separately in [`ai-architecture.md`](ai-architecture.md) and [`reconstruction-schema.md`](reconstruction-schema.md). This document remains the authoritative Phase 2 contract.
