<?php

namespace Api\Endpoints;

use FQL;
use Slim\Psr7;

class History extends Controller
{
    public function __invoke(Psr7\Request $request, Psr7\Response $response, array $args): Psr7\Response
    {
        try {
            $workspace = $this->getWorkspace($request);
            $date = isset($args['date'])
                ? \DateTime::createFromFormat('Y-m-d', $args['date'])
                : null;

            if ($date === false) {
                throw new \RuntimeException('Invalid date format');
            }

            return $this->json(
                $response,
                $workspace->getHistory($date)
            );
        } catch (\DateMalformedStringException $e) {
            return $this->json(
                $response,
                ['error' => 'Invalid date format'],
                400
            );
        } catch (FQL\Exception\FileNotFoundException $e) {
            return $this->json(
                $response,
                ['error' => 'File not found'],
                404
            );
        } catch (FQL\Exception\InvalidFormatException $e) {
            return $this->json(
                $response,
                ['error' => 'Invalid file format'],
                400
            );
        } catch (\RuntimeException $e) {
            return $this->json(
                $response,
                ['error' => $e->getMessage()],
                500
            );
        } catch (\Exception $e) {
            return $this->json(
                $response,
                ['error' => 'An unexpected error occurred'],
                500
            );
        }
    }
}
