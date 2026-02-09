(function($){
  const $msgs = $('#apai-brain-messages');
  const $pending = $('#apai-brain-pending');
  const $input = $('#apai-brain-input');
  const rest = (window.APAI_BRAIN && APAI_BRAIN.restUrl) ? APAI_BRAIN.restUrl : '';
  const nonce = (window.APAI_BRAIN && APAI_BRAIN.nonce) ? APAI_BRAIN.nonce : '';

  function addMessage(role, text){
    const cls = role === 'user' ? 'user' : 'assistant';
    const $div = $('<div/>')
      .addClass('apai-brain-msg')
      .addClass(cls)
      .text(text || '');
    $msgs.append($div);
    $msgs.scrollTop($msgs[0].scrollHeight);
  }

  function setPending(p){
    $pending.empty();
    if(!p || !p.action_id){
      $pending.append($('<div/>').addClass('apai-brain-muted').text('No hay acciones pendientes.'));
      return;
    }
    if(p.summary){
      $pending.append($('<div/>').text(p.summary));
    }
    if(p.details){
      $pending.append($('<div/>').addClass('apai-brain-muted').text(p.details));
    }

    const $actions = $('<div/>').addClass('apai-brain-actions');
    const $confirm = $('<button/>').addClass('button button-primary').text('Confirmar');
    const $cancel = $('<button/>').addClass('button').text('Cancelar');

    $confirm.on('click', function(){
      callRest('/confirm', {action_id: p.action_id}).then(function(res){
        if(res && res.reply){ addMessage('assistant', res.reply); }
        setPending(res.pending_action || null);
      });
    });

    $cancel.on('click', function(){
      callRest('/cancel', {action_id: p.action_id}).then(function(res){
        if(res && res.reply){ addMessage('assistant', res.reply); }
        setPending(null);
      });
    });

    $actions.append($confirm, $cancel);
    $pending.append($actions);
  }

  function callRest(path, body){
    return $.ajax({
      url: rest + path,
      method: 'POST',
      data: JSON.stringify(body || {}),
      contentType: 'application/json',
      headers: {'X-WP-Nonce': nonce}
    }).then(function(data){
      if(data && data.trace_id){ window.APAI_LAST_TRACE_ID = data.trace_id; }
      return data;
    }).catch(function(xhr){
      let msg = 'Error.';
      if(xhr && xhr.responseJSON && xhr.responseJSON.message){ msg = xhr.responseJSON.message; }
      addMessage('assistant', msg);
      return null;
    });
  }

  function send(){
    const text = ($input.val() || '').trim();
    if(!text) return;
    $input.val('');
    addMessage('user', text);
    callRest('/chat', {message: text}).then(function(res){
      if(!res) return;
      if(res.reply){ addMessage('assistant', res.reply); }
      setPending(res.pending_action || null);
    });
  }

  $('#apai-brain-send').on('click', send);
  $input.on('keydown', function(e){
    if(e.key === 'Enter' && !e.shiftKey){
      e.preventDefault();
      send();
    }
  });

  // initial
  setPending(null);
})(jQuery);
