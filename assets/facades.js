(function () {
  'use strict';

  const claude = window.__claude;
  if ( ! claude || ! claude.rest ) {
    console.error( '[wp-claude-bridge] facades.js loaded before auth bootstrap — aborting' );
    return;
  }

  // ===========================================================================
  // 1. window.__claude.api  — REST caller with dry_run convention
  //
  // GET/HEAD always execute. Mutating verbs (POST/PUT/PATCH/DELETE) are blocked
  // when dry_run === true and return a { dry_run: true, would: { method, path, body } }
  // object instead, so Claude can show the plan before committing.
  //
  // dry_run defaults to true so mutations are safe-by-default.
  // ===========================================================================
  function makeDryRunGuard( method, path, body, opts ) {
    return {
      dry_run : true,
      would   : { method, path, body: body ?? null },
      message : `dry_run=true: ${method} ${path} not executed. Set dry_run: false to proceed.`,
    };
  }

  claude.api = {
    get( path, params ) {
      const qs = params && Object.keys( params ).length
        ? '?' + new URLSearchParams( params ).toString()
        : '';
      return claude.rest( path + qs );
    },

    post( path, body, opts = {} ) {
      if ( opts.dry_run !== false ) { return Promise.resolve( makeDryRunGuard( 'POST', path, body ) ); }
      return claude.rest( path, { method: 'POST', body: JSON.stringify( body ) } );
    },

    put( path, body, opts = {} ) {
      if ( opts.dry_run !== false ) { return Promise.resolve( makeDryRunGuard( 'PUT', path, body ) ); }
      return claude.rest( path, { method: 'PUT', body: JSON.stringify( body ) } );
    },

    patch( path, body, opts = {} ) {
      if ( opts.dry_run !== false ) { return Promise.resolve( makeDryRunGuard( 'PATCH', path, body ) ); }
      return claude.rest( path, { method: 'PATCH', body: JSON.stringify( body ) } );
    },

    delete( path, opts = {} ) {
      if ( opts.dry_run !== false ) { return Promise.resolve( makeDryRunGuard( 'DELETE', path, null ) ); }
      return claude.rest( path, { method: 'DELETE' } );
    },
  };

  // ===========================================================================
  // 2. window.__claude.store  — wp.data read/write wrappers
  //
  // select()  is always safe (read-only).
  // dispatch() is guarded by dry_run (default true).
  // ===========================================================================
  claude.store = {
    // List every registered store name.
    list() {
      if ( ! window.wp?.data ) { return []; }
      return Object.keys( window.wp.data.stores || {} );
    },

    // Read: wp.data.select( storeName ).selectorName( ...args )
    select( storeName, selectorName, ...args ) {
      if ( ! window.wp?.data ) { throw new Error( 'wp.data not available' ); }
      const store = window.wp.data.select( storeName );
      if ( ! store ) { throw new Error( `Store not found: ${storeName}` ); }
      if ( typeof store[ selectorName ] !== 'function' ) {
        throw new Error( `Selector not found: ${storeName}.${selectorName}` );
      }
      return store[ selectorName ]( ...args );
    },

    // Write: wp.data.dispatch( storeName ).actionName( ...args )
    dispatch( storeName, actionName, args = [], opts = {} ) {
      if ( opts.dry_run !== false ) {
        return Promise.resolve( {
          dry_run : true,
          would   : { store: storeName, action: actionName, args },
          message : `dry_run=true: dispatch(${storeName}, ${actionName}) not executed. Set dry_run: false to proceed.`,
        } );
      }
      if ( ! window.wp?.data ) { return Promise.reject( new Error( 'wp.data not available' ) ); }
      const dispatcher = window.wp.data.dispatch( storeName );
      if ( typeof dispatcher[ actionName ] !== 'function' ) {
        return Promise.reject( new Error( `Action not found: ${storeName}.${actionName}` ) );
      }
      return Promise.resolve( dispatcher[ actionName ]( ...args ) );
    },
  };

  // ===========================================================================
  // 3. window.__claude.elementor  — Elementor page-builder helpers
  //
  // All writes (setWidgetSetting, addWidget, deleteWidget, save) respect dry_run.
  // ===========================================================================
  function requireElementorEditor() {
    if ( ! window.elementor ) { throw new Error( 'Elementor editor not present on this page' ); }
    return window.elementor;
  }

  claude.elementor = {
    // The active document (the page/template being edited).
    getDocument() {
      return requireElementorEditor().documents.getCurrent();
    },

    // All elements in the current document as a flat array with their models.
    getAllElements() {
      const doc = claude.elementor.getDocument();
      const out = [];
      function collect( collection ) {
        collection.each( model => {
          out.push( {
            id       : model.get( 'id' ),
            elType   : model.get( 'elType' ),
            widgetType: model.get( 'widgetType' ) || null,
            settings : model.get( 'settings' ).toJSON(),
          } );
          const inner = model.get( 'elements' );
          if ( inner?.length ) { collect( inner ); }
        } );
      }
      collect( doc.get( 'elements' ) );
      return out;
    },

    // Find widgets matching a widgetType (e.g. 'text-editor', 'heading', 'image').
    findWidgets( widgetType ) {
      return claude.elementor.getAllElements().filter( el => el.widgetType === widgetType );
    },

    // Return the Backbone model for a given element ID.
    getModel( elementId ) {
      requireElementorEditor();
      return window.elementor.getPreviewView()
        .$el.find( `[data-id="${elementId}"]` )
        .data( 'model-cid' )
        ? window.elementor.getPreviewView()
            .$el.find( `[data-id="${elementId}"]` )
            .data( 'view' )?.model
        : null;
    },

    // Read a single setting from a widget.
    getSetting( elementId, settingKey ) {
      const model = claude.elementor.getModel( elementId );
      if ( ! model ) { throw new Error( `Element not found: ${elementId}` ); }
      return model.get( 'settings' ).get( settingKey );
    },

    // Write a setting (or multiple settings) to a widget.
    setWidgetSetting( elementId, settings, opts = {} ) {
      if ( opts.dry_run !== false ) {
        return Promise.resolve( {
          dry_run : true,
          would   : { elementId, settings },
          message : `dry_run=true: setWidgetSetting(${elementId}) not executed. Set dry_run: false to proceed.`,
        } );
      }
      const model = claude.elementor.getModel( elementId );
      if ( ! model ) { return Promise.reject( new Error( `Element not found: ${elementId}` ) ); }
      model.get( 'settings' ).set( settings );
      return Promise.resolve( { elementId, updated: settings } );
    },

    // Save the current Elementor document.
    save( opts = {} ) {
      if ( opts.dry_run !== false ) {
        return Promise.resolve( {
          dry_run : true,
          would   : { action: 'elementor:save' },
          message : 'dry_run=true: Elementor save not executed. Set dry_run: false to proceed.',
        } );
      }
      requireElementorEditor();
      return new Promise( ( resolve, reject ) => {
        window.elementor.saver.saveDocument( { status: 'publish' } )
          .then( () => resolve( { saved: true } ) )
          .catch( reject );
      } );
    },
  };

  console.info( '[wp-claude-bridge] facades ready — api, store, elementor' );
} )();
