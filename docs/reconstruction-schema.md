# ReplicaForge Reconstruction Specification 3.0

This is the Phase 3 contract between design understanding and a future generator. It describes **what should be built**, not how a specific page builder stores it.

It is intentionally independent from Elementor. It must not contain `_elementor_data`, `_elementor_controls`, widget IDs, Elementor container JSON, PHP, JavaScript, SQL, shortcodes, or executable markup.

## Top-level shape

```json
{
  "schema_version": "3.0",
  "prompt_version": "1.0",
  "page_strategy": {},
  "global_styles": {},
  "sections": [],
  "components": [],
  "hierarchy": {},
  "design_system": {},
  "responsive_strategy": {},
  "assets": [],
  "content_mapping": [],
  "confidence": {},
  "warnings": [],
  "validation": {},
  "provenance": {}
}
```

`validation` and `provenance` are authoritative server-side metadata. A provider is not trusted to set `ai_used`, `validation.valid`, or cache provenance; the manager writes them after validation.

## `page_strategy`

High-level, source-backed strategy:

```json
{
  "layout_type": "structured_landing_or_content_page",
  "container_strategy": "centered_max_width",
  "section_spacing_strategy": "source_spacing_scale",
  "global_typography": "single_detected_family",
  "responsive_strategy": "evidence_backed",
  "confidence": 0.8,
  "source": "phase2_inference"
}
```

`unknown` and `not_detected` are valid values. The strategy is an inference summary, not a rendered layout claim.

## `sections`

Every section plan references an existing Phase 2 `source_id`:

```json
{
  "id": "reconstruction_section_001",
  "source_id": "section_001",
  "type": "hero",
  "order": 1,
  "confidence": 0.9,
  "layout": {
    "type": "two_column",
    "column_count": 2,
    "columns": []
  },
  "components": [ "component_001", "component_002", "component_003" ],
  "children": [],
  "responsive": {
    "desktop": { "layout": "two_column" },
    "tablet": { "layout": "unknown" },
    "mobile": { "layout": "unknown" },
    "evidence": [],
    "source_id": "section_001"
  },
  "content_summary": {
    "heading": "Build better sites",
    "text": "Supporting text",
    "source": "phase2"
  },
  "source": {
    "node_id": "node_00016",
    "tag": "section",
    "selector": "body > section"
  }
}
```

The validator rejects unknown section IDs and requires the detected sections to be represented when the Phase 3 section limit permits it. Layout values are static evidence only; no rendered dimensions are implied.

## `components`

Each plan references an existing Phase 2 component:

```json
{
  "id": "reconstruction_component_003",
  "source_id": "component_003",
  "type": "button",
  "reconstruction_type": "button",
  "role": "call_to_action",
  "semantic_role": "primary_or_secondary_cta",
  "content_source": "source",
  "confidence": 0.9,
  "section_id": "section_001",
  "children": [],
  "target": "https://example.com/contact",
  "source": {}
}
```

Allowed generic reconstruction types are deliberately small:

```text
heading
paragraph
button
link
image
background_image
card
product_card
testimonial
pricing_card
form
form_field
navigation_link
logo
icon
gallery
accordion
tabs
tab
list
label
feature_card
blog_card
team_card
portfolio_card
repeated_card_group
unknown
```

`content_source` is one of `source`, `not_detected`, or `unknown`. A component plan never supplies replacement text, a new product, a new price, or a new URL.

## `hierarchy`

The hierarchy is a generic structural tree:

```json
{
  "sections": {
    "section_001": {
      "source_id": "section_001",
      "children": [
        {
          "type": "container",
          "source_id": "section_001",
          "layout": {},
          "children": [
            {
              "type": "component_group",
              "source_id": "section_001",
              "component_ids": [ "component_001", "component_002" ]
            }
          ]
        }
      ]
    }
  },
  "component_groups": {
    "primary": [ "component_001" ],
    "secondary": [],
    "supporting": [ "component_002" ],
    "cta": [ "component_003" ],
    "decorative": []
  }
}
```

The tree expresses detected relationships. It does not claim browser z-index, visibility, or pixel positions.

## `design_system`

The design-system section interprets existing tokens into generic roles:

```json
{
  "colors": [
    {
      "role": "primary",
      "value": "#635bff",
      "confidence": 0.6,
      "source": "phase2_design_token",
      "evidence": [ ".btn" ]
    }
  ],
  "typography": [
    {
      "role": "heading_1",
      "font_family": "Inter",
      "font_size": "48px",
      "font_weight": 700,
      "line_height": 1.1,
      "confidence": 0.8,
      "source": "phase2_typography_token"
    }
  ],
  "spacing": { "scale": [ "16px", "32px" ] },
  "radius": { "scale": [ "8px" ] },
  "shadows": [],
  "buttons": [],
  "confidence": 0.68,
  "source": "phase2_design_system"
}
```

Colors, typography, spacing, radius, shadows, and button values must originate in Phase 2 evidence. A semantic role carries confidence and source. Missing values remain `null` or an explicit unknown marker.

## `responsive_strategy`

Responsive output is evidence-backed:

```json
{
  "desktop": { "layout": "stack" },
  "tablet": { "layout": "unknown" },
  "mobile": { "layout": "responsive_layout_change_detected" },
  "evidence": [
    {
      "id": "responsive_evidence_001",
      "viewport": "mobile",
      "breakpoint": "768px",
      "changes": [ "layout_change" ],
      "selectors": [ ".hero" ],
      "confidence": 0.68,
      "source": "phase2_responsive_rule"
    }
  ],
  "section_strategies": {},
  "confidence": 0.72,
  "source": "phase2_responsive_evidence"
}
```

The validator rejects a non-unknown tablet/mobile section inference when no corresponding Phase 2 responsive evidence exists. Responsive typography and spacing values are preserved only when source data supplies them.

## `assets`

Assets are references, not downloads:

```json
{
  "id": "asset_001",
  "source_id": "component_004",
  "type": "image",
  "role": "product_image",
  "usage": "product_image",
  "source_url": "https://example.com/one.jpg",
  "preserve": true,
  "reason": null,
  "confidence": 0.84,
  "source": {}
}
```

If the URL is missing or unsafe:

```json
{
  "source_url": null,
  "preserve": false,
  "reason": "asset_unavailable_or_unsafe"
}
```

Phase 3 does not download, replace, or generate images.

## `content_mapping`

Content is copied from source evidence:

```json
{
  "id": "content_001",
  "component_id": "component_001",
  "content_type": "heading",
  "source": "phase2",
  "value": "Build better sites",
  "source_reference": {}
}
```

Product fields remain separate and exact:

```json
{
  "component_id": "component_004",
  "content_type": "field",
  "field": "price",
  "source": "phase2",
  "value": "$19.00"
}
```

A missing price, rating, image, or link is `null`. Lorem ipsum, sample products, invented prices, and invented destinations are rejected.

## `confidence` and `validation`

Confidence is an inference score in the inclusive `0..1` range:

```json
{
  "overall": 0.8,
  "sections": 0.88,
  "components": 0.9,
  "design_system": 0.68,
  "responsive": 0.72,
  "inference": 0.8
}
```

The admin labels this **inference confidence**. It is not an accuracy percentage and does not imply pixel-perfect rendering.

`validation` is written by the server after source-consistency checks:

```json
{
  "valid": true,
  "errors": [],
  "warnings": []
}
```

## Phase 4 input boundary

A future generator should consume this specification and the Phase 2 representation. It must not:

- fetch or parse the target HTML again;
- accept provider prose as instructions;
- invent missing content or assets;
- assume that `unknown` responsive values are mobile stacking;
- use `confidence` as proof of visual equivalence.

Phase 4 may map these generic records to Elementor containers, widgets, and controls. That mapping belongs in Phase 4, not in Phase 3.
