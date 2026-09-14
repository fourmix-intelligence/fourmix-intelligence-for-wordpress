(function (blocks, element, i18n, blockEditor, components) {
  const el = element.createElement;
  const __ = i18n.__;
  const definitions = {
    'ai-concierge': ['AIに相談', 'お客様の質問に会話形式でご案内します。'],
    'site-search': ['AIサイト内検索', 'サイトの情報から質問に直接回答します。'],
    'related-content': ['AI関連記事案内', '現在のページに合う情報を自動で案内します。'],
    'product-recommendation': ['AI商品提案', '用途や希望に合う商品を提案します。'],
    'frequently-bought-together': ['AIまとめ買い提案', '現在の商品やカートに合う商品を自動提案します。'],
    'cart-assistant': ['AIカート確認', '買い忘れや組み合わせを購入前に確認します。']
  };
  Object.keys(definitions).forEach(function (kind) {
    blocks.registerBlockType('fourmix-intelligence/' + kind, {
      edit: function (props) {
        const attrs = props.attributes;
        return el('div', blockEditor.useBlockProps({className: 'fmi-editor'}),
          el(blockEditor.InspectorControls, {}, el(components.PanelBody, {title: __('表示設定', 'fourmix-intelligence')},
            el(components.TextControl, {label: __('見出し', 'fourmix-intelligence'), value: attrs.title || definitions[kind][0], onChange: function(v){props.setAttributes({title:v});}}),
            el(components.ToggleControl, {label: __('ページ表示時に自動で案内する', 'fourmix-intelligence'), checked: !!attrs.automatic, onChange: function(v){props.setAttributes({automatic:v});}})
          )),
          el('span', {className: 'fmi-editor__mark'}, '✦'), el('div', {}, el('strong', {}, attrs.title || definitions[kind][0]), el('p', {}, definitions[kind][1]))
        );
      },
      save: function () { return null; }
    });
  });
})(window.wp.blocks, window.wp.element, window.wp.i18n, window.wp.blockEditor, window.wp.components);

