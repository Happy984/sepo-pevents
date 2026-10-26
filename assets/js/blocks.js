/* Sepo Events — the "Sepo event" and "Sepo events list" blocks. No build step. */
(function (wp, cfg) {
  if (!wp || !wp.blocks) { return; }
  var el = wp.element.createElement;
  var __ = wp.i18n.__;
  var be = wp.blockEditor || wp.editor;
  var C = wp.components;
  var SSR = wp.serverSideRender;
  var events = (cfg && cfg.events) || [];

  function preview(name, attrs, props) {
    return el('div', props, SSR ? el(SSR, { block: name, attributes: attrs }) : null);
  }

  wp.blocks.registerBlockType('sepo/event', {
    apiVersion: 2,
    title: __('Sepo event', 'sepo-events'),
    description: __('Shows one event with its RSVP form.', 'sepo-events'),
    icon: 'calendar-alt',
    category: 'widgets',
    keywords: [__('event', 'sepo-events'), __('rsvp', 'sepo-events'), __('registration', 'sepo-events')],
    attributes: { eventId: { type: 'number', default: 0 }, showTitle: { type: 'boolean', default: true } },
    edit: function (props) {
      var a = props.attributes;
      var bp = be.useBlockProps ? be.useBlockProps() : {};
      var options = [{ value: 0, label: __('Choose an event…', 'sepo-events') }].concat(events);
      var pick = function (v) { props.setAttributes({ eventId: parseInt(v, 10) || 0 }); };
      var controls = el(be.InspectorControls, null,
        el(C.PanelBody, { title: __('Event', 'sepo-events'), initialOpen: true },
          el(C.SelectControl, { label: __('Event', 'sepo-events'), value: a.eventId, options: options, onChange: pick }),
          el(C.ToggleControl, { label: __('Show the event title', 'sepo-events'), checked: !!a.showTitle, onChange: function (v) { props.setAttributes({ showTitle: v }); } })
        )
      );
      if (!a.eventId) {
        return el('div', bp, controls, el(C.Placeholder, { icon: 'calendar-alt', label: __('Sepo event', 'sepo-events'), instructions: events.length ? __('Choose the event to show.', 'sepo-events') : __('Create an event under Events → Add event first.', 'sepo-events') },
          events.length ? el(C.SelectControl, { value: 0, options: options, onChange: pick }) : null));
      }
      return el('div', bp, controls, preview('sepo/event', a, { style: { pointerEvents: 'none' } }));
    },
    save: function () { return null; }
  });

  wp.blocks.registerBlockType('sepo/events-list', {
    apiVersion: 2,
    title: __('Sepo events list', 'sepo-events'),
    description: __('Lists your events: upcoming, past, or both.', 'sepo-events'),
    icon: 'list-view',
    category: 'widgets',
    keywords: [__('events', 'sepo-events'), __('calendar', 'sepo-events')],
    attributes: { show: { type: 'string', default: 'all' }, limit: { type: 'number', default: 0 }, past: { type: 'number', default: 0 }, title: { type: 'string', default: '' }, perPage: { type: 'number', default: 9 }, view: { type: 'string', default: 'grid' }, toolbar: { type: 'boolean', default: true } },
    edit: function (props) {
      var a = props.attributes;
      var bp = be.useBlockProps ? be.useBlockProps() : {};
      return el('div', bp,
        el(be.InspectorControls, null,
          el(C.PanelBody, { title: __('List', 'sepo-events'), initialOpen: true },
            el(C.SelectControl, { label: __('Show', 'sepo-events'), value: a.show, options: [
              { value: 'all', label: __('Upcoming and past', 'sepo-events') },
              { value: 'upcoming', label: __('Upcoming only', 'sepo-events') },
              { value: 'past', label: __('Past only', 'sepo-events') }
            ], onChange: function (v) { props.setAttributes({ show: v }); } }),
            el(C.RangeControl, { label: __('Most events to show (0 for all)', 'sepo-events'), value: a.limit, min: 0, max: 48, onChange: function (v) { props.setAttributes({ limit: v || 0 }); } }),
            el(C.RangeControl, { label: __('Events per page', 'sepo-events'), value: a.perPage, min: 3, max: 48, onChange: function (v) { props.setAttributes({ perPage: v || 9 }); } }),
            el(C.SelectControl, { label: __('Layout', 'sepo-events'), value: a.view, options: [{ value: 'grid', label: __('Grid', 'sepo-events') }, { value: 'list', label: __('List', 'sepo-events') }], onChange: function (v) { props.setAttributes({ view: v }); } }),
            el(C.ToggleControl, { label: __('Show search and filters', 'sepo-events'), checked: !!a.toolbar, onChange: function (v) { props.setAttributes({ toolbar: v }); } }),
            el(C.TextControl, { label: __('Heading', 'sepo-events'), value: a.title, onChange: function (v) { props.setAttributes({ title: v }); } })
          )
        ),
        preview('sepo/events-list', a, { style: { pointerEvents: 'none' } })
      );
    },
    save: function () { return null; }
  });
})(window.wp, window.SepoEventsBlocks);
