<?php

use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Thunk\Verbs\Examples\Wingspan\Events\GainedFood;
use Thunk\Verbs\Examples\Wingspan\Events\GameStarted;
use Thunk\Verbs\Examples\Wingspan\Events\PlayedBird;
use Thunk\Verbs\Examples\Wingspan\Events\PlayerSetUp;
use Thunk\Verbs\Examples\Wingspan\Events\RoundStarted;
use Thunk\Verbs\Examples\Wingspan\Events\SelectedAsFirstPlayer;
use Thunk\Verbs\Examples\Wingspan\Game\Birds\BaldEagle;
use Thunk\Verbs\Examples\Wingspan\Game\Birds\Bird;
use Thunk\Verbs\Examples\Wingspan\Game\Birds\Crow;
use Thunk\Verbs\Examples\Wingspan\Game\Birds\Goldfinch;
use Thunk\Verbs\Examples\Wingspan\Game\Birds\Hawk;
use Thunk\Verbs\Examples\Wingspan\Game\Birds\Nuthatch;
use Thunk\Verbs\Examples\Wingspan\Game\Food;
use Thunk\Verbs\Examples\Wingspan\States\GameState;
use Thunk\Verbs\Examples\Wingspan\States\RoundState;
use Thunk\Verbs\Exceptions\EventNotValidForCurrentState;
use Thunk\Verbs\Support\EventSerializer;

beforeEach(fn () => EventSerializer::$custom_normalizers = [
    new class implements DenormalizerInterface, NormalizerInterface
    {
        public function supportsDenormalization(mixed $data, string $type, string $format = null): bool
        {
            return is_a($type, Bird::class, true);
        }

        public function denormalize(mixed $data, string $type, string $format = null, array $context = [])
        {
            if ($data instanceof Bird) {
                return $data;
            }

            if (is_a($data, Bird::class, true)) {
                return new $data();
            }

            throw new UnexpectedValueException;
        }

        public function supportsNormalization(mixed $data, string $format = null): bool
        {
            return $data instanceof Bird;
        }

        public function normalize(mixed $object, string $format = null, array $context = []): string
        {
            return $object::class;
        }

        public function getSupportedTypes(?string $format): array
        {
            return [Bird::class => true];
        }
    },
]);

it('can play a game of wingspan', function () {
    // We shouldn't be able to start a game with an invalid number of players
    expect(fn () => GameStarted::fire(players: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => GameStarted::fire(players: 6))->toThrow(InvalidArgumentException::class);

    // Game setup
    // ---------------------------------------------------------------------------------------------------------------------------

    $start_event = GameStarted::fire(players: 2);
    $game_state = $start_event->state(GameState::class);

    $player1_state = $start_event->playerState(0);
    $player2_state = $start_event->playerState(1);

    expect($game_state->started)->toBeTrue()
        ->and($game_state->round)->toBe(0)
        ->and($game_state->setup_count)->toBe(0)
        ->and($player1_state->setup)->toBe(false)
        ->and($player1_state->available_action_cubes)->toBe(8)
        ->and($player2_state->setup)->toBe(false)
        ->and($player2_state->available_action_cubes)->toBe(8)
        ->and(fn () => GameStarted::fire(players: 2, game_id: $game_state->id))->toThrow(EventNotValidForCurrentState::class);

    PlayerSetUp::fire(
        player_id: $player1_state->id,
        game_id: $game_state->id,
        bird_cards: [new Hawk(), new Crow()],
        bonus_card: 'bonus1',
        food: [Food::Worm, Food::Wheat, Food::Berries],
    );

    expect($game_state->setup_count)->toBe(1)
        ->and($player1_state->setup)->toBe(true)
        ->and($player1_state->bird_cards->is([new Hawk(), new Crow()]))->toBeTrue()
        ->and($player1_state->bonus_cards)->toBe(['bonus1'])
        ->and($player1_state->food->is([Food::Worm, Food::Wheat, Food::Berries]))->toBeTrue()
        ->and($player2_state->setup)->toBe(false);

    PlayerSetUp::fire(
        player_id: $player2_state->id,
        game_id: $game_state->id,
        bird_cards: [new BaldEagle(), new Nuthatch(), new Goldfinch()],
        bonus_card: 'bonus2',
        food: [Food::Mouse, Food::Berries],
    );

    expect($game_state->setup_count)->toBe(2)
        ->and($player1_state->setup)->toBe(true)
        ->and($player2_state->setup)->toBe(true)
        ->and($player2_state->bird_cards->is([new BaldEagle(), new Nuthatch(), new Goldfinch()]))->toBeTrue()
        ->and($player2_state->bonus_cards)->toBe(['bonus2'])
        ->and($player2_state->food->is([Food::Mouse, Food::Berries]))->toBeTrue();

    SelectedAsFirstPlayer::fire(
        player_id: $player2_state->id,
        game_id: $game_state->id,
    );

    expect($player1_state->first_player)->toBe(false)
        ->and($player2_state->first_player)->toBe(true)
        ->and($game_state->isSetUp())->toBeTrue();

    // First Round
    // ---------------------------------------------------------------------------------------------------------------------------

    $round1_state = RoundStarted::fire(game_id: $game_state->id)->state(RoundState::class);

    expect($game_state->round)->toBe(1);

    PlayedBird::fire(
        player_id: $player1_state->id,
        round_id: $round1_state->id,
        bird: new Crow(),
        food: [Food::Wheat, Food::Berries],
    );

    expect($player1_state->bird_cards->is([new Hawk()]))->toBeTrue()
        ->and($player1_state->food->is([Food::Worm]))->toBeTrue()
        ->and($player1_state->available_action_cubes)->toBe(7)
        ->and($player1_state->grass_birds->is([new Crow()]))->toBeTrue();

    GainedFood::fire(
        player_id: $player2_state->id,
        food: Food::Fish,
    );

    expect($player2_state->food->is([Food::Mouse, Food::Berries, Food::Fish]))->toBeTrue()
        ->and($player2_state->available_action_cubes)->toBe(7);
});
