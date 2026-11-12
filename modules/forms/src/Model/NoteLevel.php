<?php

namespace BarrioSuite\Forms\Model;

// Stessi valori del CHECK ben_level_chk: lo verifica SchemaTest.
enum NoteLevel: string {
	case Info    = 'info';
	case Success = 'success';
	case Error   = 'error';
}
